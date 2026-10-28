<?php
namespace OcMcp;

/**
 * Minimal OAuth 2.1 authorization server for the MCP endpoint (RFC 6749/6750/7591/7636/8414/8707/9207/9728 subset).
 *
 *  - Client ID Metadata Documents (client_id = https URL, fetched and cached) and Dynamic Client Registration.
 *  - Authorization code + PKCE S256 only; the resource owner is an OpenCart administrator who logs in on the consent page.
 *  - Opaque access / refresh tokens stored hashed in `mcp_oauth_token`; access tokens are validated by Auth on every MCP call.
 *
 * Everything is session-less and framework-agnostic; the OpenCart controller supplies URLs, the admin-credential check
 * and the HTML renderer.
 */
final class OAuthServer {
    const SCOPE_READ  = 'store:read';
    const SCOPE_WRITE = 'store:write';

    const CODE_TTL    = 600;       // 10 min
    const ACCESS_TTL  = 3600;      // 1 h
    const REFRESH_TTL = 2592000;   // 30 days
    /** End of a connection the owner keeps without a time limit (setting "Connections without a time limit"). */
    const FOREVER     = '2099-12-31 23:59:59';
    const CIMD_TTL    = 86400;     // re-fetch client metadata documents daily
    const MAX_FAILED_LOGINS = 10;  // per IP per 15 minutes
    const MAX_ACCOUNT_LOGINS = 20; // per login name per 15 minutes, from all IPs together (password spraying)

    /** @var Db */
    private $db;
    /** @var array issuer, resource, authorize, token, register, resource_metadata, as_metadata */
    private $urls;
    /** @var string */
    private $key;
    /** @var string */
    private $storeName;
    /** @var Logger|null */
    private $logger;
    /** @var bool connections do not run out (the owner's setting); otherwise 30 days from the last refresh */
    private $unlimited = false;

    public function __construct(Db $db, array $urls, string $signingKey, string $storeName = '', ?Logger $logger = null) {
        $this->db = $db;
        $this->urls = $urls;
        $this->key = $signingKey;
        $this->storeName = $storeName;
        $this->logger = $logger;
    }

    /** Connections without a time limit: a new or refreshed connection gets no end date. */
    public function unlimited(bool $on): self {
        $this->unlimited = $on;

        return $this;
    }

    /** Requests per IP: registrations per hour, token / authorize calls per minute (0 = unlimited). */
    const LIMIT_REGISTER_HOUR = 20;
    const LIMIT_TOKEN_MINUTE = 60;
    const LIMIT_AUTHORIZE_MINUTE = 60;

    /** 429 response when $ip went over the limit of $endpoint, otherwise null. Fails open when the counter table is missing. */
    private function throttle(HttpRequest $req, string $endpoint, int $limit, int $window): ?HttpResponse {
        if ($limit <= 0 || $req->method === 'OPTIONS') {
            return null;
        }

        $limiter = new RateLimiter($this->db, $window);

        if ($limiter->hit('oauth:' . $endpoint . ':' . $req->ip, $limit)) {
            return null;
        }

        $this->log($req->ip, '_oauth_rate_limited', ['endpoint' => $endpoint], 'denied');
        $r = self::jsonPublic(429, ['error' => 'slow_down', 'error_description' => 'Too many requests from this address, try again later']);
        $r->withHeader('Retry-After', (string)$limiter->retryAfter());

        return $r;
    }

    // ------------------------------------------------------------------ metadata documents

    public function protectedResourceMetadata(): array {
        return [
            'resource'                 => $this->urls['resource'],
            'authorization_servers'    => [$this->urls['issuer']],
            'scopes_supported'         => [self::SCOPE_READ, self::SCOPE_WRITE],
            'bearer_methods_supported' => ['header'],
            'resource_name'            => trim($this->storeName . ' (OpenCart MCP)'),
        ];
    }

    public function authorizationServerMetadata(): array {
        return [
            'issuer'                                          => $this->urls['issuer'],
            'authorization_endpoint'                          => $this->urls['authorize'],
            'token_endpoint'                                  => $this->urls['token'],
            'registration_endpoint'                           => $this->urls['register'],
            'scopes_supported'                                => [self::SCOPE_READ, self::SCOPE_WRITE],
            'response_types_supported'                        => ['code'],
            'response_modes_supported'                        => ['query'],
            'grant_types_supported'                           => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported'           => ['none', 'client_secret_post', 'client_secret_basic'],
            'code_challenge_methods_supported'                => ['S256'],
            'authorization_response_iss_parameter_supported'  => true,
            'client_id_metadata_document_supported'           => true,
        ];
    }

    public static function jsonPublic(int $status, array $data): HttpResponse {
        $r = HttpResponse::json($status, $data);
        $r->withHeader('Access-Control-Allow-Origin', '*');
        $r->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $r->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, MCP-Protocol-Version');
        $r->withHeader('Cache-Control', 'no-store');

        return $r;
    }

    // ------------------------------------------------------------------ dynamic client registration

    public function handleRegister(HttpRequest $req): HttpResponse {
        if ($blocked = License::gate($this->db)) {
            return $blocked;
        }

        if ($r = $this->throttle($req, 'register', self::LIMIT_REGISTER_HOUR, 3600)) {
            return $r;
        }

        if ($req->method === 'OPTIONS') {
            return self::jsonPublic(204, []);
        }

        if ($req->method !== 'POST') {
            return self::jsonPublic(405, ['error' => 'invalid_request', 'error_description' => 'POST a client metadata document']);
        }

        $meta = json_decode($req->body, true);

        if (!is_array($meta)) {
            return self::jsonPublic(400, ['error' => 'invalid_client_metadata', 'error_description' => 'Body must be a JSON object']);
        }

        $redirects = isset($meta['redirect_uris']) && is_array($meta['redirect_uris']) ? array_values(array_filter(array_map('strval', $meta['redirect_uris']))) : [];

        if (!$redirects) {
            return self::jsonPublic(400, ['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris is required']);
        }

        foreach ($redirects as $uri) {
            if (!self::validRedirect($uri)) {
                return self::jsonPublic(400, ['error' => 'invalid_redirect_uri', 'error_description' => 'Redirect URIs must use https (or http on localhost / 127.0.0.1): ' . $uri]);
            }
        }

        $authMethod = (string)($meta['token_endpoint_auth_method'] ?? 'none');

        if (!in_array($authMethod, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            return self::jsonPublic(400, ['error' => 'invalid_client_metadata', 'error_description' => 'Unsupported token_endpoint_auth_method: ' . $authMethod]);
        }

        $clientId = 'mcp_' . bin2hex(random_bytes(16));
        $secret = $authMethod === 'none' ? null : self::randomToken(32);
        $name = self::cleanName((string)($meta['client_name'] ?? ''), 'MCP client');

        $this->db->exec("INSERT INTO " . $this->db->t('mcp_oauth_client') . " SET"
            . " `client_id` = " . $this->db->q($clientId) . ","
            . " `client_secret_hash` = " . ($secret === null ? 'NULL' : $this->db->q(self::hash($secret))) . ","
            . " `client_name` = " . $this->db->q($name) . ","
            . " `redirect_uris` = " . $this->db->q(json_encode($redirects)) . ","
            . " `auth_method` = " . $this->db->q($authMethod) . ","
            . " `source` = 'dcr',"
            . " `metadata` = " . $this->db->q(mb_substr((string)json_encode($meta, JSON_UNESCAPED_SLASHES), 0, 20000)) . ","
            . " `date_added` = NOW(), `date_fetched` = NOW()");

        $this->log($req->ip, 'oauth_register', ['client_name' => $name, 'redirect_uris' => $redirects], 'ok');

        $out = [
            'client_id'                  => $clientId,
            'client_id_issued_at'        => time(),
            'client_name'                => $name,
            'redirect_uris'              => $redirects,
            'token_endpoint_auth_method' => $authMethod,
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'scope'                      => self::SCOPE_READ . ' ' . self::SCOPE_WRITE,
        ];

        if ($secret !== null) {
            $out['client_secret'] = $secret;
            $out['client_secret_expires_at'] = 0;
        }

        return self::jsonPublic(201, $out);
    }

    // ------------------------------------------------------------------ authorization endpoint

    /**
     * @param callable $verifyAdmin  function(string $username, string $password): ?array  (user_id, username, name)
     * @param callable $render       function(array $view): string  HTML of the consent page
     */
    public function handleAuthorize(HttpRequest $req, callable $verifyAdmin, callable $render, bool $allowWrite = true): HttpResponse {
        if ($blocked = License::gate($this->db)) {
            return $blocked;
        }

        if ($r = $this->throttle($req, 'authorize', self::LIMIT_AUTHORIZE_MINUTE, 60)) {
            return $r;
        }

        $this->purge();

        $p = $req->query;

        if ($req->method === 'POST') {
            $post = [];
            parse_str($req->body, $post);
            $p = array_merge($p, $post);
        }

        $get = function (string $k) use ($p): string {
            return isset($p[$k]) && !is_array($p[$k]) ? trim((string)$p[$k]) : '';
        };

        $clientId = $get('client_id');
        $redirect = $get('redirect_uri');
        $state = $get('state');
        $challenge = $get('code_challenge');
        $method = $get('code_challenge_method') ?: 'S256';
        $scope = $get('scope');
        $resource = $get('resource');

        $client = $clientId !== '' ? $this->resolveClient($clientId) : null;

        if ($client === null) {
            return $this->errorPage($render, 400, 'unknown_client', 'Unknown or invalid client_id. The AI client must register first (Dynamic Client Registration or a Client ID Metadata Document).');
        }

        $registered = json_decode((string)$client['redirect_uris'], true) ?: [];

        if ($redirect === '' || !in_array($redirect, $registered, true)) {
            return $this->errorPage($render, 400, 'invalid_redirect_uri', 'redirect_uri is not registered for this client.');
        }

        // From here on errors may be returned to the (validated) redirect URI.
        if ($get('response_type') !== 'code') {
            return $this->redirectWith($redirect, ['error' => 'unsupported_response_type', 'state' => $state]);
        }

        if ($challenge === '' || strlen($challenge) < 43 || strlen($challenge) > 128 || $method !== 'S256') {
            return $this->redirectWith($redirect, ['error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required', 'state' => $state]);
        }

        $requestedScopes = array_values(array_intersect(preg_split('/\s+/', $scope) ?: [], [self::SCOPE_READ, self::SCOPE_WRITE]));

        if (!$requestedScopes) {
            $requestedScopes = [self::SCOPE_READ, self::SCOPE_WRITE];
        }

        // every connection may do what the Access tab allows: the sign-in page offers no narrower choice ($allowWrite is kept for the callers)

        $view = [
            'store_name'   => $this->storeName,
            'client_name'  => (string)$client['client_name'],
            'client_id'    => $clientId,
            'client_source'=> (string)$client['source'],
            'redirect_uri' => $redirect,
            'scopes'       => $requestedScopes,
            'action'       => $this->urls['authorize'],
            'params'       => ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => implode(' ', $requestedScopes), 'resource' => $resource],
            'csrf'         => $this->csrf($clientId, $redirect, $challenge),
            'username'     => '',
            'error'        => null,
        ];

        if ($req->method !== 'POST' || $get('mcp_consent') === '') {
            return self::html(200, $render($view));
        }

        if ($get('mcp_consent') === 'deny') {
            $this->log($req->ip, 'oauth_denied', ['client' => $client['client_name']], 'ok');

            return $this->redirectWith($redirect, ['error' => 'access_denied', 'error_description' => 'The store administrator declined the connection', 'state' => $state]);
        }

        $view['username'] = $get('username');

        if (!hash_equals($this->csrf($clientId, $redirect, $challenge), $get('csrf'))) {
            $view['error'] = 'csrf';

            return self::html(400, $render($view));
        }

        // reserve the attempt atomically BEFORE checking the password (parallel guesses cannot all pass a stale count),
        // and keep the failure count from the log as a second, longer-lived signal
        $limiter = new RateLimiter($this->db, 900);

        if (!$limiter->hit('oauth-login:' . $req->ip, self::MAX_FAILED_LOGINS)
            || !$limiter->hit('oauth-login-user:' . sha1(mb_strtolower(trim($get('username')))), self::MAX_ACCOUNT_LOGINS)
            || $this->tooManyFailures($req->ip, $get('username'))) {
            $view['error'] = 'rate_limited';

            return self::html(429, $render($view));
        }

        $user = $verifyAdmin($get('username'), $get('password'));

        // right password on a clerk's account (no module permission) answers exactly like a wrong one: the page must not confirm a guessed password
        if (!$user || !empty($user['no_permission'])) {
            usleep(500000);
            $this->log($req->ip, '_oauth_login_failed', ['username' => $get('username'), 'client' => $client['client_name']] + ($user ? ['reason' => 'no module permission'] : []), 'denied');
            $view['error'] = 'login';

            return self::html(401, $render($view));
        }

        $grantedScopes = [self::SCOPE_READ, self::SCOPE_WRITE]; // what the assistant may actually change is decided on the Access tab at call time

        $code = self::randomToken(32);

        $this->db->exec("INSERT INTO " . $this->db->t('mcp_oauth_code') . " SET"
            . " `code_hash` = " . $this->db->q(self::hash($code)) . ","
            . " `client_id` = " . $this->db->q($clientId) . ","
            . " `redirect_uri` = " . $this->db->q($redirect) . ","
            . " `code_challenge` = " . $this->db->q($challenge) . ","
            . " `scope` = " . $this->db->q(implode(' ', $grantedScopes)) . ","
            . " `resource` = " . $this->db->q($resource) . ","
            . " `user_id` = " . (int)($user['user_id'] ?? 0) . ","
            . " `username` = " . $this->db->q((string)($user['username'] ?? '')) . ","
            . " `expires` = " . $this->db->q(date('Y-m-d H:i:s', time() + self::CODE_TTL)) . ","
            . " `used` = 0");

        $this->log($req->ip, 'oauth_grant', ['client' => $client['client_name'], 'user' => $user['username'] ?? '', 'scope' => implode(' ', $grantedScopes)], 'ok');

        return $this->redirectWith($redirect, ['code' => $code, 'state' => $state, 'iss' => $this->urls['issuer']]);
    }

    // ------------------------------------------------------------------ token endpoint

    public function handleToken(HttpRequest $req): HttpResponse {
        if ($blocked = License::gate($this->db)) {
            return $blocked;
        }

        if ($r = $this->throttle($req, 'token', self::LIMIT_TOKEN_MINUTE, 60)) {
            return $r;
        }

        if ($req->method === 'OPTIONS') {
            return self::jsonPublic(204, []);
        }

        if ($req->method !== 'POST') {
            return self::tokenError(405, 'invalid_request', 'POST only');
        }

        $p = [];
        $ct = (string)$req->header('content-type');

        if (stripos($ct, 'application/json') !== false) {
            $p = json_decode($req->body, true) ?: [];
        } else {
            parse_str($req->body, $p);
        }

        $get = function (string $k) use ($p): string {
            return isset($p[$k]) && !is_array($p[$k]) ? (string)$p[$k] : '';
        };

        $clientId = $get('client_id');
        $clientSecret = $get('client_secret');
        $authz = (string)$req->header('authorization');

        if (preg_match('/^\s*Basic\s+(.+)$/i', $authz, $m)) {
            $decoded = base64_decode(trim($m[1]), true);

            if ($decoded !== false && strpos($decoded, ':') !== false) {
                list($bu, $bp) = explode(':', $decoded, 2);
                $clientId = $clientId !== '' ? $clientId : rawurldecode($bu);
                $clientSecret = rawurldecode($bp);
            }
        }

        $grant = $get('grant_type');

        if ($grant === 'authorization_code') {
            $code = $get('code');
            $verifier = $get('code_verifier');

            if ($code === '' || $verifier === '') {
                return self::tokenError(400, 'invalid_request', 'code and code_verifier are required');
            }

            $row = $this->db->row("SELECT * FROM " . $this->db->t('mcp_oauth_code') . " WHERE `code_hash` = " . $this->db->q(self::hash($code)));

            if ($row === null || (int)$row['used'] === 1 || strtotime((string)$row['expires']) < time()) {
                return self::tokenError(400, 'invalid_grant', 'Authorization code is invalid, expired or already used');
            }

            if ($clientId === '') {
                $clientId = (string)$row['client_id'];
            }

            if ($clientId !== (string)$row['client_id']) {
                return self::tokenError(400, 'invalid_grant', 'client_id does not match the authorization code');
            }

            $client = $this->clientRow($clientId);

            if ($client === null || !$this->clientAuthenticated($client, $clientSecret)) {
                return self::tokenError(401, 'invalid_client', 'Client authentication failed');
            }

            $redirect = $get('redirect_uri');

            if ($redirect !== '' && $redirect !== (string)$row['redirect_uri']) {
                return self::tokenError(400, 'invalid_grant', 'redirect_uri mismatch');
            }

            if (!hash_equals((string)$row['code_challenge'], self::b64url(hash('sha256', $verifier, true)))) {
                return self::tokenError(400, 'invalid_grant', 'PKCE verification failed');
            }

            // consume the code in ONE guarded statement: of two parallel exchanges only one gets affected = 1
            if ($this->db->exec("UPDATE " . $this->db->t('mcp_oauth_code') . " SET `used` = 1 WHERE `code_hash` = " . $this->db->q(self::hash($code)) . " AND `used` = 0 AND `expires` >= NOW()") !== 1) {
                return self::tokenError(400, 'invalid_grant', 'Authorization code is invalid, expired or already used');
            }

            return $this->issueTokens($req, $client, (int)$row['user_id'], (string)$row['username'], (string)$row['scope'], null);
        }

        if ($grant === 'refresh_token') {
            $refresh = $get('refresh_token');

            if ($refresh === '') {
                return self::tokenError(400, 'invalid_request', 'refresh_token is required');
            }

            $row = $this->db->row("SELECT * FROM " . $this->db->t('mcp_oauth_token') . " WHERE `refresh_hash` = " . $this->db->q(self::hash($refresh)));

            if ($row === null || (int)$row['revoked'] === 1 || strtotime((string)$row['refresh_expires']) < time()) {
                return self::tokenError(400, 'invalid_grant', 'Refresh token is invalid, expired or revoked');
            }

            if ($clientId === '') {
                $clientId = (string)$row['client_id'];
            }

            if ($clientId !== (string)$row['client_id']) {
                return self::tokenError(400, 'invalid_grant', 'client_id does not match the refresh token');
            }

            $client = $this->clientRow($clientId);

            if ($client === null || !$this->clientAuthenticated($client, $clientSecret)) {
                return self::tokenError(401, 'invalid_client', 'Client authentication failed');
            }

            $scope = (string)$row['scope']; // a client asking for a narrower scope on refresh keeps the full one; the token answer names the issued scope (RFC 6749 §5.1)

            return $this->issueTokens($req, $client, (int)$row['user_id'], (string)$row['username'], $scope, (int)$row['token_id']);
        }

        return self::tokenError(400, 'unsupported_grant_type', 'Use authorization_code or refresh_token');
    }

    private function issueTokens(HttpRequest $req, array $client, int $userId, string $username, string $scope, ?int $rotateFrom): HttpResponse {
        $access = self::randomToken(32);
        $refresh = self::randomToken(32);
        $now = time();

        if ($rotateFrom !== null) {
            // rotation: retire the old refresh token in ONE guarded statement. A parallel refresh with the same token, or an
            // admin revoke that landed after our read, leaves 0 affected rows -> no new tokens.
            if ($this->db->exec("UPDATE " . $this->db->t('mcp_oauth_token') . " SET `revoked` = 1 WHERE `token_id` = " . $rotateFrom . " AND `revoked` = 0 AND `refresh_expires` >= NOW()") !== 1) {
                return self::tokenError(400, 'invalid_grant', 'Refresh token is invalid, expired, revoked or already used');
            }
        }

        $this->db->exec("INSERT INTO " . $this->db->t('mcp_oauth_token') . " SET"
            . " `access_hash` = " . $this->db->q(self::hash($access)) . ","
            . " `refresh_hash` = " . $this->db->q(self::hash($refresh)) . ","
            . " `client_id` = " . $this->db->q((string)$client['client_id']) . ","
            . " `client_name` = " . $this->db->q((string)$client['client_name']) . ","
            . " `user_id` = " . $userId . ","
            . " `username` = " . $this->db->q($username) . ","
            . " `scope` = " . $this->db->q($scope) . ","
            . " `access_expires` = " . $this->db->q(date('Y-m-d H:i:s', $now + self::ACCESS_TTL)) . ","
            . " `refresh_expires` = " . $this->db->q($this->unlimited ? self::FOREVER : date('Y-m-d H:i:s', $now + self::REFRESH_TTL)) . ","
            . " `revoked` = 0, `ip` = " . $this->db->q(substr($req->ip, 0, 45)) . ", `date_added` = NOW(), `last_used` = NOW()");

        $this->log($req->ip, $rotateFrom === null ? 'oauth_token' : 'oauth_refresh', ['client' => $client['client_name'], 'user' => $username, 'scope' => $scope], 'ok');

        return self::jsonPublic(200, [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope'         => $scope,
        ]);
    }

    private static function tokenError(int $status, string $error, string $description): HttpResponse {
        return self::jsonPublic($status, ['error' => $error, 'error_description' => $description]);
    }

    // ------------------------------------------------------------------ bearer validation (called by Auth)

    /** @return array|null grant info: token_id, client_name, username, scope, scopes[] */
    public function validateBearer(string $token): ?array {
        if ($token === '' || strlen($token) > 200) {
            return null;
        }

        $row = $this->db->row("SELECT `token_id`, `client_id`, `client_name`, `user_id`, `username`, `scope`, `access_expires`, `revoked` FROM " . $this->db->t('mcp_oauth_token') . " WHERE `access_hash` = " . $this->db->q(self::hash($token)));

        if ($row === null || (int)$row['revoked'] === 1 || strtotime((string)$row['access_expires']) < time()) {
            return null;
        }

        $this->db->exec("UPDATE " . $this->db->t('mcp_oauth_token') . " SET `last_used` = NOW() WHERE `token_id` = " . (int)$row['token_id']);

        return [
            'token_id'    => (int)$row['token_id'],
            'client_id'   => (string)$row['client_id'],
            'client_name' => (string)$row['client_name'],
            'user_id'     => (int)$row['user_id'],
            'username'    => (string)$row['username'],
            'scope'       => (string)$row['scope'],
            'scopes'      => preg_split('/\s+/', trim((string)$row['scope'])) ?: [],
        ];
    }

    // ------------------------------------------------------------------ admin helpers

    public function listTokens(int $limit = 200): array {
        return $this->db->rows("SELECT `token_id`, `client_id`, `client_name`, `user_id`, `username`, `scope`, `access_expires`, `refresh_expires`, `revoked`, `ip`, `date_added`, `last_used` FROM " . $this->db->t('mcp_oauth_token') . " ORDER BY `token_id` DESC LIMIT " . (int)$limit);
    }

    public function revokeToken(int $tokenId): void {
        $this->db->exec("UPDATE " . $this->db->t('mcp_oauth_token') . " SET `revoked` = 1 WHERE `token_id` = " . $tokenId);
    }

    public function revokeAll(): void {
        $this->db->exec("UPDATE " . $this->db->t('mcp_oauth_token') . " SET `revoked` = 1");
    }

    public function purge(): void {
        try {
            $this->db->exec("DELETE FROM " . $this->db->t('mcp_oauth_code') . " WHERE `expires` < DATE_SUB(NOW(), INTERVAL 1 DAY)");
            $this->db->exec("DELETE FROM " . $this->db->t('mcp_oauth_token') . " WHERE `refresh_expires` < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        } catch (\Throwable $e) {
            // housekeeping only
        }
    }

    // ------------------------------------------------------------------ clients

    private function clientRow(string $clientId): ?array {
        return $this->db->row("SELECT * FROM " . $this->db->t('mcp_oauth_client') . " WHERE `client_id` = " . $this->db->q($clientId));
    }

    private function clientAuthenticated(array $client, string $secret): bool {
        if ((string)$client['auth_method'] === 'none' || $client['client_secret_hash'] === null || $client['client_secret_hash'] === '') {
            return true;
        }

        return $secret !== '' && hash_equals((string)$client['client_secret_hash'], self::hash($secret));
    }

    /** Registered client, or a Client ID Metadata Document fetched (and cached) from an https client_id. */
    private function resolveClient(string $clientId): ?array {
        $row = $this->clientRow($clientId);

        if (preg_match('#^https://#i', $clientId)) {
            if ($row !== null && strtotime((string)$row['date_fetched']) > time() - self::CIMD_TTL) {
                return $row;
            }

            $doc = $this->fetchClientDocument($clientId);

            if ($doc === null) {
                return $row; // use the cached copy if the fetch failed
            }

            $redirects = isset($doc['redirect_uris']) && is_array($doc['redirect_uris']) ? array_values(array_filter(array_map('strval', $doc['redirect_uris']), [self::class, 'validRedirect'])) : [];

            if (!$redirects || (isset($doc['client_id']) && (string)$doc['client_id'] !== $clientId)) {
                return $row;
            }

            $name = self::cleanName((string)($doc['client_name'] ?? ''), (string)parse_url($clientId, PHP_URL_HOST));
            $methods = isset($doc['token_endpoint_auth_methods_supported']) && is_array($doc['token_endpoint_auth_methods_supported']) ? $doc['token_endpoint_auth_methods_supported'] : [(string)($doc['token_endpoint_auth_method'] ?? 'none')];
            $authMethod = in_array('none', $methods, true) || !$methods ? 'none' : 'none'; // CIMD clients are public clients for us

            $set = " `client_name` = " . $this->db->q($name) . ", `redirect_uris` = " . $this->db->q(json_encode($redirects)) . ", `auth_method` = " . $this->db->q($authMethod)
                . ", `source` = 'cimd', `metadata` = " . $this->db->q(mb_substr((string)json_encode($doc, JSON_UNESCAPED_SLASHES), 0, 20000)) . ", `date_fetched` = NOW()";

            // one statement for "insert or refresh": two first sign-ins of the same client at once cannot collide on the key
            $this->db->exec("INSERT INTO " . $this->db->t('mcp_oauth_client') . " SET `client_id` = " . $this->db->q($clientId) . ", `client_secret_hash` = NULL," . $set . ", `date_added` = NOW()"
                . " ON DUPLICATE KEY UPDATE" . $set);

            return $this->clientRow($clientId);
        }

        return $row;
    }

    /**
     * Fetch a Client ID Metadata Document. SSRF-safe: https on port 443 only, the host must resolve exclusively to
     * public IP addresses, the connection is pinned to the checked address (no DNS rebinding), no redirects, 64 KB max.
     */
    private function fetchClientDocument(string $url): ?array {
        $parts = parse_url($url);

        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || (isset($parts['port']) && (int)$parts['port'] !== 443) || isset($parts['user']) || strlen($url) > 2048) {
            return null;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $ips = NetGuard::resolvePublic($host);

        if (!$ips || !function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'opencart-mcp-server/' . Server::VERSION, CURLOPT_MAXFILESIZE => 65536,
            CURLOPT_RESOLVE => [$host . ':443:' . (strpos($ips[0], ':') !== false ? '[' . $ips[0] . ']' : $ips[0])],
        ];

        if (defined('CURLOPT_PROTOCOLS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body) || $status !== 200 || strlen($body) > 65536) {
            return null;
        }

        $doc = json_decode($body, true);

        return is_array($doc) ? $doc : null;
    }



    public static function validRedirect(string $uri): bool {
        $parts = parse_url($uri);

        if (!$parts || empty($parts['scheme'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme === 'https') {
            return !empty($parts['host']);
        }

        if ($scheme === 'http') {
            return !empty($parts['host']) && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true);
        }

        // custom schemes of desktop apps (cursor://, vscode://, claude://...): must have an authority, never script-ish schemes
        if (in_array($scheme, ['javascript', 'data', 'file', 'vbscript', 'blob', 'about'], true)) {
            return false;
        }

        return (bool)preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) && !empty($parts['host']) && empty($parts['fragment']);
    }

    // ------------------------------------------------------------------ misc helpers

    private function redirectWith(string $uri, array $params): HttpResponse {
        $params = array_filter($params, function ($v) {
            return $v !== null && $v !== '';
        });
        $sep = strpos($uri, '?') === false ? '?' : '&';
        $r = HttpResponse::noContent(302);
        $r->withHeader('Location', $uri . ($params ? $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : ''));
        $r->withHeader('Cache-Control', 'no-store');

        return $r;
    }

    private function errorPage(callable $render, int $status, string $error, string $description): HttpResponse {
        return self::html($status, $render(['fatal' => $error, 'fatal_description' => $description, 'store_name' => $this->storeName]));
    }

    public static function html(int $status, string $body): HttpResponse {
        $r = new HttpResponse();
        $r->status = $status;
        $r->headers['Content-Type'] = 'text/html; charset=utf-8';
        $r->headers['Cache-Control'] = 'no-store';
        $r->headers['X-Frame-Options'] = 'DENY';
        $r->headers['Referrer-Policy'] = 'no-referrer';
        $r->body = $body;

        return $r;
    }

    private function csrf(string $clientId, string $redirect, string $challenge): string {
        return hash_hmac('sha256', $clientId . '|' . $redirect . '|' . $challenge, $this->key);
    }

    /** Failed logins of this user name from this address in the last 15 minutes (all attempts per address are capped separately). */
    private function tooManyFailures(string $ip, string $username): bool {
        try {
            $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], substr((string)json_encode(['username' => $username], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1)) . '%';
            $n = (int)$this->db->value("SELECT COUNT(*) AS c FROM " . $this->db->t(Logger::TABLE) . " WHERE `tool` = '_oauth_login_failed' AND `ip` = " . $this->db->q($ip)
                . " AND `arguments` LIKE " . $this->db->q($needle) . " AND `date_added` > DATE_SUB(NOW(), INTERVAL 15 MINUTE)", 'c');

            return $n >= self::MAX_FAILED_LOGINS;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function log(string $ip, string $event, array $data, string $status): void {
        if ($this->logger) {
            $this->logger->log($ip, 'oauth', $event, $data, $status);
        }
    }

    /** A client-supplied display name without tags, control characters or markup symbols, at most 120 characters. */
    public static function cleanName(string $name, string $fallback): string {
        $name = trim(preg_replace('/\s+/u', ' ', (string)preg_replace('/[\x00-\x1F\x7F<>"\'`]/u', '', strip_tags($name))));
        return mb_substr($name !== '' ? $name : $fallback, 0, 120);
    }

    public static function randomToken(int $bytes = 32): string {
        return self::b64url(random_bytes($bytes));
    }

    public static function hash(string $value): string {
        return hash('sha256', $value);
    }

    public static function b64url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
