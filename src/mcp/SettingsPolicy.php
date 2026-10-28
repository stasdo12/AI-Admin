<?php
namespace OcMcp;

/**
 * Which store settings (oc_setting) the AI may see and change: only the store's own (code "config"). Settings of extensions
 * (payment, shipping, theme, order totals, any other module) are never shown: they hold bank details and API keys under
 * names no pattern can be trusted to catch.
 *
 * Raw SQL on oc_setting is always closed (it also holds SMTP passwords and payment keys). Settings are reached only
 * through get_settings / update_settings, filtered here:
 *  - never readable: real secrets only (passwords, keys, tokens, salts, SMTP login, encryption key) and this module's own settings
 *  - readable but never editable: contact details and technical switches (phone, e-mail, address, URL, SSL, maintenance...)
 *  - editable (when the owner sets System > Settings to "Edit"): every other readable setting, narrowed by the owner's
 *    "Edit" ticks in the Details dialog. EDITABLE is the "Recommended" preset's choice; values are checked below.
 */
final class SettingsPolicy {
    const SECRET_PATTERNS = ['/secret/i', '/password/i', '/passwd/i', '/_key$/i', '/_key_/i', '/token/i', '/salt/i', '/smtp_(username|password)/i', '/api_/i', '/encryption/i', '/signature/i', '/merchant/i', '/_pass$/i', '/_login$/i'];

    /** Match a secret pattern but are plain switches and numbers ("Allow forgotten password", password length, which API user the admin uses). */
    const NOT_SECRET = ['config_password', 'config_password_length', 'config_user_password_length', 'config_api_id'];

    const NEVER_EDIT = ['config_telephone', 'config_email', 'config_address', 'config_owner', 'config_fax', 'config_geocode', 'config_url', 'config_ssl', 'config_secure', 'config_maintenance',
                        'config_seo_url', 'config_compression', 'config_currency_auto', 'config_currency_engine', 'config_language', 'config_admin_language', 'config_language_admin', 'config_currency',
                        'config_country_id', 'config_zone_id', 'config_store_id', 'config_theme', 'config_template', 'config_image', 'config_logo', 'config_icon',
                        // readable for answers, but they steer mail, uploads, errors and sign-in: changed by the owner only
                        'config_mail_engine', 'config_mail_parameter', 'config_mail_smtp_hostname', 'config_mail_smtp_port', 'config_mail_smtp_timeout', 'config_mail_alert', 'config_mail_alert_email',
                        'config_file_max_size', 'config_file_ext_allowed', 'config_file_mime_allowed', 'config_error_display', 'config_error_log', 'config_error_filename',
                        'config_robots', 'config_shared', 'config_api_id', 'config_password', 'config_password_length', 'config_user_password_length', 'config_security'];

    const EDITABLE = [
        'config_name', 'config_meta_title', 'config_meta_description', 'config_meta_keyword', 'config_description', 'config_comment', 'config_open',
        'config_stock_display', 'config_stock_warning', 'config_stock_checkout', 'config_stock_status_id',
        'config_order_status_id', 'config_processing_status', 'config_complete_status', 'config_fraud_status_id', 'config_return_status_id',
        'config_review_status', 'config_review_guest', 'config_product_count', 'config_product_limit', 'config_pagination', 'config_pagination_admin',
        'config_product_description_length', 'config_cart_weight', 'config_checkout_guest', 'config_invoice_prefix', 'config_voucher_min', 'config_voucher_max',
        'config_customer_group_display', 'config_customer_price', 'config_login_attempts', 'config_affiliate_commission', 'config_affiliate_auto', 'config_return_id',
    ];

    /** Integer settings with a sane range (0 or negative page sizes break pagination; 0 login attempts locks everyone out). */
    const INT_RANGES = [
        'config_product_limit' => [1, 1000], 'config_pagination' => [1, 1000], 'config_pagination_admin' => [1, 1000],
        'config_product_description_length' => [1, 10000], 'config_login_attempts' => [1, 100], 'config_voucher_min' => [0, 1000000],
        'config_voucher_max' => [0, 1000000], 'config_affiliate_commission' => [0, 100], 'config_stock_warning' => [0, 1],
    ];

    /** Settings that change every address of the store (log 2026-10-12: the trailing slash of SEO Pro cut the AI connection off). */
    const URL_PATTERN = '/seo|postfix|addslash|lowercase|canonical|valide_param|timezone/i';

    /** A warning for the preview when some of these keys change the store's addresses or clock, else null. */
    public static function urlWarning(array $keys): ?string {
        $hit = array_values(array_filter($keys, function ($k) { return (bool)preg_match(self::URL_PATTERN, (string)$k); }));

        return $hit ? 'Careful: ' . implode(', ', $hit) . ' change(s) how every address of the store looks (or its clock): search engines see new links, old links may redirect, and an AI connection or payment callback can break. Change these one at a time, never in a batch with others, and check the store and the AI connection after each one.' : null;
    }

    /** 0 / 1 switches. */
    const SWITCHES = ['config_stock_display', 'config_stock_checkout', 'config_review_status', 'config_review_guest', 'config_product_count',
                      'config_cart_weight', 'config_checkout_guest', 'config_customer_price', 'config_affiliate_auto'];

    /** Settings that point at a status row: key => [table, id column]. */
    const STATUS_REFS = ['config_stock_status_id' => ['stock_status', 'stock_status_id'], 'config_order_status_id' => ['order_status', 'order_status_id'],
                         'config_fraud_status_id' => ['order_status', 'order_status_id'], 'config_return_status_id' => ['return_status', 'return_status_id']];

    /**
     * Validate new values before anything is written. Returns an error message, or null when all values are fine.
     * @param array $settings key => new value; $current key => stored value (for cross-checks like voucher min <= max)
     */
    public static function validate(array $settings, array $current, Db $db): ?string {
        // every wrong value at once: one refused key must not cost the AI one call per key to find the others
        $errors = [];

        foreach ($settings as $key => $value) {
            if (($e = self::check([$key => $value], $current, $db)) !== null) {
                $errors[] = $e;
            }
        }

        $vmin = $settings['config_voucher_min'] ?? $current['config_voucher_min'] ?? null;
        $vmax = $settings['config_voucher_max'] ?? $current['config_voucher_max'] ?? null;

        if (is_numeric($vmin) && is_numeric($vmax) && (float)$vmax > 0 && (float)$vmin > (float)$vmax) {
            $errors[] = 'config_voucher_min cannot be larger than config_voucher_max.';
        }

        if (count($errors) > 1) {
            return count($errors) . ' values are wrong, nothing was changed: ' . implode(' ', $errors) . ' Fix or leave out these keys; the others are fine.';
        }

        return $errors[0] ?? null;
    }

    private static function check(array $settings, array $current, Db $db): ?string {
        foreach ($settings as $key => $value) {
            if (isset(self::INT_RANGES[$key])) {
                [$min, $max] = self::INT_RANGES[$key];

                if (!is_numeric($value) || (float)$value != (int)$value || (int)$value < $min || (int)$value > $max) {
                    return "$key must be a whole number from $min to $max.";
                }
            }

            if (in_array($key, self::SWITCHES, true) && !in_array((string)(is_bool($value) ? (int)$value : $value), ['0', '1'], true)) {
                return "$key must be 0 or 1.";
            }

            if (isset(self::STATUS_REFS[$key])) {
                [$table, $col] = self::STATUS_REFS[$key];

                if (!is_numeric($value) || !$db->row("SELECT 1 AS x FROM " . $db->t($table) . " WHERE `" . $col . "` = " . (int)$value . " LIMIT 1")) {
                    return "$key: status $value does not exist.";
                }
            }

            if (in_array($key, ['config_processing_status', 'config_complete_status'], true)) {
                $ids = is_array($value) ? $value : [];

                if (!$ids || array_filter($ids, function ($v) { return !is_numeric($v); })) {
                    return "$key must be a non-empty list of order status ids.";
                }
            }

            if (in_array($key, ['config_name', 'config_meta_title', 'config_invoice_prefix'], true) && (!is_string($value) || trim($value) === '' || mb_strlen($value) > 255)) {
                return "$key must be a non-empty text of at most 255 characters.";
            }
        }

        // any other setting: the same shape as now (a list stays a list, a value stays one value), a sane size
        foreach ($settings as $key => $value) {
            if (isset(self::INT_RANGES[$key]) || in_array($key, self::SWITCHES, true) || isset(self::STATUS_REFS[$key])) {
                continue;
            }

            $now = $current[$key] ?? null;

            if (is_string($now) && $now !== '' && ($now[0] === '[' || $now[0] === '{') && is_array($j = json_decode($now, true))) {
                $now = $j; // stored serialized: a list or an object
            }

            if (is_array($now) !== is_array($value) && $now !== null && $now !== '') {
                return "$key must be " . (is_array($now) ? 'a list or object, like now' : 'a single value, like now') . '.';
            }

            if (!is_array($value) && !is_scalar($value) && $value !== null) {
                return "$key: unsupported value.";
            }

            if (strlen((string)json_encode($value)) > 65535) {
                return "$key: the value is too long.";
            }

            if (is_numeric($now) && !is_array($value) && (string)$value !== '' && !is_numeric($value) && preg_match('/_(id|limit|length|width|height|count|status|quantity|time|interval|attempts)$/', $key)) {
                return "$key must be a number, like now.";
            }
        }

        return null;
    }

    public static function isSecret(string $key): bool {
        if (in_array($key, self::NOT_SECRET, true)) {
            return false;
        }

        foreach (self::SECRET_PATTERNS as $re) {
            if (preg_match($re, $key)) {
                return true;
            }
        }

        return strpos($key, 'config_') !== 0; // anything that is not the store's own setting: extensions keep bank details and keys there
    }

    public static function canEditKey(string $key): bool {
        return !self::isSecret($key) && !in_array($key, self::NEVER_EDIT, true);
    }

    /** Readable keys of a store (secrets removed). */
    public static function readableKeys(Db $db, int $storeId = 0): array {
        $out = [];

        foreach ($db->rows("SELECT `key` FROM " . $db->t('setting') . " WHERE `store_id` = " . $storeId . " AND " . self::codeSql($db) . " ORDER BY `code` = 'config' DESC, `code`, `key`") as $r) {
            if (!self::isSecret($r['key'])) {
                $out[] = $r['key'];
            }
        }

        return $out;
    }

    /** The setting rows the AI may read: the store's own and its extensions' (each key still filtered by isSecret). */
    public static function codeSql(Db $db): string {
        return "`code` = 'config'";
    }
}
