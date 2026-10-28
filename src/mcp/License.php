<?php
namespace OcMcp;

/**
 * Offline license check. A key is  <prefix>.<base64url(payload JSON)>.<base64url(signature of "<prefix>." + payload part)>
 *   OCMCP2  ECDSA P-256 / SHA-256, checked with the openssl extension: every host that runs OpenCart has it (the OpenCart
 *           installer refuses to install without it), so a key works wherever the store works.
 * Payload: {"v":1,"id":"...","licensee":"...","domains":["shop.com"],"subdomains":true,"issued":"2026-10-05","expires":null}
 * The signing keys stay with the module owner (tools/license/keygen.php); only the public keys are in the module.
 * A key is valid for its domains (and their subdomains when "subdomains" is true); a new domain needs a new key.
 * "www." is ignored on both sides. Expired keys stop working at the end of the "expires" day (UTC).
 */
final class License {
    const PREFIX2 = 'OCMCP2'; // the only key format (the Ed25519 "OCMCP1" format was dropped on 2026-10-09 before any customer key existed: no sodium dependency)

    /**
     * FREE / demo build: one universal key that works on every domain, no signing. The free distribution is read-only
     * (all write tools are removed from the PHP), so there is nothing to protect with a per-domain signed key - the goal
     * is only that the module runs out of the box. The owner enters this in the admin, or the build fills it in.
     */
    const FREE_KEY = 'ai-admin-free';

    /** ECDSA P-256 public key (PEM); the signing key is ~/.opencart-mcp-license/owner-ec.key on the owner's machine. */
    const PUBLIC_KEY_EC = "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEgb3YtFcBAuf+yeusTdHHHteSSIY6\nVS4ieL9oPYvbhIGU2DB9u3ZE5RddjsB6exuzqc25ojVbeYW6qmuZGh58gg==\n-----END PUBLIC KEY-----\n";

    /** @var string PEM */
    private $publicKeyEc;

    public function __construct(?string $publicKeyEcPem = null) {
        $this->publicKeyEc = $publicKeyEcPem ?? self::PUBLIC_KEY_EC;
    }

    /** Which signature check this PHP can do: openssl (every host that runs OpenCart has it). */
    public static function cryptoAvailable(): array {
        return ['openssl' => function_exists('openssl_verify')];
    }

    /**
     * @return array{valid:bool, reason:string, payload:?array}  reason: ok | empty | format | signature | domain | expired | crypto
     */
    public function check(string $key, string $host): array {
        $key = trim($key);

        if ($key === '') {
            return ['valid' => false, 'reason' => 'empty', 'payload' => null];
        }

        // FREE / demo build: the universal key is valid on any domain (read-only distribution).
        if ($key === self::FREE_KEY) {
            return ['valid' => true, 'reason' => 'ok', 'payload' => ['v' => 1, 'id' => 'free', 'licensee' => 'AI Admin Free', 'domains' => ['*'], 'subdomains' => true, 'issued' => '2026-10-21', 'expires' => null, 'free' => true]];
        }

        $parts = explode('.', $key);

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX2) {
            return ['valid' => false, 'reason' => 'format', 'payload' => null];
        }

        $payloadJson = self::b64d($parts[1]);
        $signature = self::b64d($parts[2]);

        if ($payloadJson === null || $signature === null || $signature === '') {
            return ['valid' => false, 'reason' => 'format', 'payload' => null];
        }

        if (!function_exists('openssl_verify')) {
            return ['valid' => false, 'reason' => 'crypto', 'payload' => null];
        }

        try {
            $ok = @openssl_verify(self::PREFIX2 . '.' . $parts[1], $signature, $this->publicKeyEc, OPENSSL_ALGO_SHA256) === 1;
        } catch (\Throwable $e) {
            $ok = false;
        }

        if (!$ok) {
            return ['valid' => false, 'reason' => 'signature', 'payload' => null];
        }

        $p = json_decode($payloadJson, true);

        if (!is_array($p) || (int)($p['v'] ?? 0) !== 1 || empty($p['domains']) || !is_array($p['domains'])) {
            return ['valid' => false, 'reason' => 'format', 'payload' => null];
        }

        if (!empty($p['expires']) && strtotime($p['expires'] . ' 23:59:59 UTC') < time()) {
            return ['valid' => false, 'reason' => 'expired', 'payload' => $p];
        }

        if (!self::hostMatches(self::normalizeHost($host), $p['domains'], !empty($p['subdomains']))) {
            return ['valid' => false, 'reason' => 'domain', 'payload' => $p];
        }

        return ['valid' => true, 'reason' => 'ok', 'payload' => $p];
    }

    /** @var array|null per-request cache of storeCheck() */
    private static $storeResult = null;

    /**
     * The license state of THIS store, decided inside the core (which ships encoded): the key is read from the store's
     * settings table and the domain from OpenCart's own config constants, not from anything the calling controller passes.
     */
    public static function storeCheck(Db $db): array {
        if (self::$storeResult === null) {
            $key = '';

            try {
                $key = (string)$db->value("SELECT `value` FROM " . $db->t('setting') . " WHERE `store_id` = 0 AND `key` = 'module_mcp_server_license_key' LIMIT 1", 'value');
            } catch (\Throwable $e) {
            }

            $url = defined('HTTP_CATALOG') ? HTTP_CATALOG : (defined('HTTPS_SERVER') && HTTPS_SERVER ? HTTPS_SERVER : (defined('HTTP_SERVER') ? HTTP_SERVER : ''));
            self::$storeResult = (new self())->check($key, self::normalizeHost((string)$url));
        }

        return self::$storeResult;
    }

    /**
     * A second, independently named license gate for callers already in the hot path (tool dispatch). Not a replacement
     * for gate(): it is a separate check site so removing one does not leave the other open. Fails closed.
     */
    public static function permit(Db $db): bool {
        try {
            return self::storeCheck($db)['valid'] === true;
        } catch (\Throwable $e) {
            return false; // anything unexpected is treated as "not licensed"
        }
    }

    /**
     * Fold the store's license into a signing secret. The OAuth consent CSRF and the preview->apply approval codes are
     * signed with the result, so the License class is load-bearing: delete or stub it and sign-in and change confirmations
     * stop working, not only the 403 - a lazy "comment out the gate" crack leaves a half-broken module. The fingerprint is
     * the license id (stable for a valid key, so no churn for honest stores; it changes only when a different key is
     * installed, after which clients re-authorise once). HONEST LIMIT: with readable PHP this raises the effort to remove
     * the check, it does not make removal cryptographically impossible - the client still holds everything needed to run.
     */
    public static function mix(Db $db, string $base): string {
        $fp = 'unlicensed';

        try {
            $r = self::storeCheck($db);

            if ($r['valid'] && isset($r['payload']['id']) && is_string($r['payload']['id'])) {
                $fp = $r['payload']['id'];
            }
        } catch (\Throwable $e) {
        }

        return hash_hmac('sha256', 'ocmcp-sign-v1|' . $fp, $base !== '' ? $base : 'ocmcp-base');
    }

    /** 403 response when this store has no valid license, otherwise null. */
    public static function gate(Db $db): ?HttpResponse {
        $r = self::storeCheck($db);

        return $r['valid'] ? null : HttpResponse::json(403, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001,
            'message' => 'The MCP server module of this store has no valid license for this domain (' . $r['reason'] . '). The store owner enters the license key in the admin: Extensions > Modules > MCP Server.']]);
    }

    public static function normalizeHost(string $hostOrUrl): string {
        $h = strtolower(trim($hostOrUrl));

        if (strpos($h, '://') !== false) {
            $h = (string)parse_url($h, PHP_URL_HOST);
        }

        $h = preg_replace('/:\d+$/', '', $h);
        $h = rtrim((string)$h, '.');

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $h)) {
            $a = @idn_to_ascii($h, 0, defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0);
            $h = $a ?: $h;
        }

        return strpos($h, 'www.') === 0 ? substr($h, 4) : $h;
    }

    public static function hostMatches(string $host, array $domains, bool $subdomains): bool {
        foreach ($domains as $d) {
            $d = self::normalizeHost((string)$d);

            if ($d !== '' && ($host === $d || ($subdomains && substr($host, -strlen('.' . $d)) === '.' . $d))) {
                return true;
            }
        }

        return false;
    }

    /** The store's own host: the configured catalog URL, never the request's Host header (which a client controls). */
    public static function storeHost(string $catalogUrl): string {
        return self::normalizeHost($catalogUrl);
    }

    private static function b64d(string $s): ?string {
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);

        return $r === false ? null : $r;
    }

    public static function b64e(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
