<?php
namespace OcMcp;

/**
 * Request authentication.
 *
 * Accepted credentials (any one is enough):
 *  1. `Authorization: Bearer <token>`           - preferred (Claude.ai "Request headers", Claude Code, Cursor...)
 *  2. `X-MCP-Token: <token>` / `X-API-Key`       - fallback for hosts that strip the Authorization header
 *  3. URL secret: the request came in through the SEO alias `https://shop/<secret>` or `?k=<secret>`
 *     - for clients that cannot send custom headers and have no OAuth yet (ChatGPT, Claude.ai without the headers beta)
 *
 * Optional IP allow-list on top. Fails closed when nothing is configured.
 */
final class Auth {
    /** @var string */
    private $token;
    /** @var string */
    private $urlSecret;
    /** @var string sha256 of the static token (preferred storage) */
    private $tokenHash;
    /** @var string[] */
    private $allowedIps;
    /** @var callable|null function(string $bearer): ?array - validates OAuth access tokens */
    private $bearerValidator;
    /** @var array|null grant info of the OAuth token that authorized the request */
    public $grant = null;

    public function __construct(string $token, string $urlSecret = '', string $allowedIps = '', ?callable $bearerValidator = null, string $tokenHash = '') {
        $this->token = trim($token);
        $this->tokenHash = strtolower(trim($tokenHash));
        $this->urlSecret = trim($urlSecret);
        $this->allowedIps = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $allowedIps))));
        $this->bearerValidator = $bearerValidator;
    }

    /**
     * @return true|string true when authorized, otherwise a short reason.
     */
    public function check(HttpRequest $req) {
        if ($this->allowedIps && !$this->ipAllowed($req->ip)) {
            return 'ip_not_allowed';
        }

        if ($this->token === '' && $this->tokenHash === '' && $this->urlSecret === '' && $this->bearerValidator === null) {
            return 'server_not_configured';
        }

        if ($this->token !== '' || $this->tokenHash !== '') {
            foreach ($this->presentedTokens($req) as $candidate) {
                if ($candidate === '') {
                    continue;
                }

                if ($this->tokenHash !== '' && hash_equals($this->tokenHash, hash('sha256', $candidate))) {
                    return true;
                }

                if ($this->token !== '' && hash_equals($this->token, $candidate)) {
                    return true; // legacy plain-text token from v0.1/v0.2 settings
                }
            }
        }

        if ($this->bearerValidator !== null) {
            $auth = (string)$req->header('authorization');

            if (preg_match('/^\s*Bearer\s+(.+)$/i', $auth, $m)) {
                $grant = call_user_func($this->bearerValidator, trim($m[1]));

                if (is_array($grant)) {
                    $this->grant = $grant;

                    return true;
                }
            }
        }

        if ($this->urlSecret !== '') {
            $candidates = [];

            if ($req->routeKeyword !== '') {
                $candidates[] = trim($req->routeKeyword, '/');
            }

            if (isset($req->query['k'])) {
                $candidates[] = (string)$req->query['k'];
            }

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && hash_equals($this->urlSecret, $candidate)) {
                    return true;
                }
            }
        }

        return 'invalid_token';
    }

    /** @return string[] */
    private function presentedTokens(HttpRequest $req): array {
        $list = [];
        $auth = $req->header('authorization');

        if ($auth !== null) {
            if (preg_match('/^\s*Bearer\s+(.+)$/i', $auth, $m)) {
                $list[] = trim($m[1]);
            } else {
                $list[] = trim($auth);
            }
        }

        foreach (['x-mcp-token', 'x-api-key', 'x-auth-token'] as $name) {
            $v = $req->header($name);

            if ($v !== null) {
                $list[] = trim($v);
            }
        }

        return $list;
    }

    private function ipAllowed(string $ip): bool {
        foreach ($this->allowedIps as $rule) {
            if ($rule === $ip) {
                return true;
            }

            if (strpos($rule, '/') !== false && self::cidrMatch($ip, $rule)) {
                return true;
            }

            if (substr($rule, -1) === '*' && strpos($ip, rtrim($rule, '*')) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function cidrMatch(string $ip, string $cidr): bool {
        list($subnet, $bits) = explode('/', $cidr, 2);
        $bits = (int)$bits;
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);

        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
            return false;
        }

        if ($rem === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subBin[$bytes]) & $mask);
    }
}
