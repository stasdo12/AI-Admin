<?php
namespace OcMcp;

/**
 * Exports products, orders or customers to a CSV file the owner downloads by a signed, short-lived link.
 *
 * The AI only creates the link (ExportData tool); the data never enters the chat, so there is no size limit and the
 * personal-data budget does not apply. The file is generated on the fly when the link is opened (no stored files to
 * clean up) and always obeys the current Access tab: a closed section cannot be exported, hidden columns are left out.
 * The token is HMAC-signed with the store's key and expires, so a leaked link yields only what the Access tab allows.
 */
final class DataExport {
    const ENTITIES = ['products', 'orders', 'customers'];
    const MAX_MINUTES = 120;

    /** Desired columns per entity; only those the table actually has (version differences) and the owner has not hidden are used. */
    const COLUMNS = [
        'products'  => ['product_id', 'model', 'sku', 'upc', 'ean', 'location', 'price', 'quantity', 'status', 'date_added'],
        'orders'    => ['order_id', 'date_added', 'firstname', 'lastname', 'email', 'telephone', 'order_status_id', 'total', 'currency_code', 'payment_code', 'shipping_code', 'store_name'],
        'customers' => ['customer_id', 'firstname', 'lastname', 'email', 'telephone', 'customer_group_id', 'newsletter', 'status', 'ip', 'date_added'],
    ];

    /** Main table of each entity (access and hidden columns are read from it). */
    const TABLE = ['products' => 'product', 'orders' => 'order', 'customers' => 'customer'];

    /** Signing key: the same the approval codes use, so no new secret and no DB password is needed on the download route. */
    public static function key(Context $c): string {
        $k = (string)$c->config('module_mcp_server_oauth_key', '');

        return $k !== '' ? $k : hash('sha256', 'mcp-export|' . $c->db->prefix() . '|' . $c->storeUrl);
    }

    public static function sign(array $payload, string $key): string {
        $body = self::b64(json_encode($payload));

        return $body . '.' . self::b64(hash_hmac('sha256', $body, $key, true));
    }

    /** @return array|null the payload if the token is valid and not expired, else null */
    public static function verify(string $token, string $key): ?array {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return null;
        }

        if (!hash_equals(self::b64(hash_hmac('sha256', $parts[0], $key, true)), $parts[1])) {
            return null;
        }

        $payload = json_decode(self::unb64($parts[0]), true);

        if (!is_array($payload) || (int)($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    /** Access check for an entity: null if it can be exported, otherwise a ready denial message. */
    public static function denial(Context $c, string $entity): ?string {
        $table = self::TABLE[$entity];

        if (!$c->hasTable($table)) {
            return 'This store has no ' . $entity . ' table.';
        }

        if (!$c->policy()->canRead($table)) {
            return null; // the caller turns this into the standard ACCESS DENIED via AccessDenied
        }

        return '';
    }

    /** Count the rows the export would hold (for the tool's message). */
    public static function count(Context $c, string $entity, array $filters): int {
        [$from, $where] = self::query($c, $entity, $filters);

        return (int)$c->db->value("SELECT COUNT(*) AS n FROM " . $from . ($where !== '' ? " WHERE " . $where : ''), 'n');
    }

    /** Build and stream the CSV to php://output, honouring the current Access tab. */
    public static function stream(Context $c, string $entity, array $filters): void {
        $db = $c->db;
        $cols = self::selectColumns($c, $entity);
        [$from, $where, $idExpr] = self::query($c, $entity, $filters);
        $labels = array_keys($cols);
        $select = [];

        foreach ($cols as $label => $expr) {
            $select[] = $expr . " AS `" . $label . "`"; // alias every column, or a subquery comes back keyed by its own text
        }

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Cyrillic correctly
        Csv::put($out, $labels);

        $last = 0;

        do {
            $sql = "SELECT " . $idExpr . " AS `_id`, " . implode(', ', $select) . " FROM " . $from
                . " WHERE " . ($where !== '' ? '(' . $where . ') AND ' : '') . $idExpr . " > " . (int)$last
                . " ORDER BY " . $idExpr . " ASC LIMIT 1000";
            $rows = $db->rows($sql);

            foreach ($rows as $r) {
                $last = (int)$r['_id'];
                $line = [];

                foreach ($labels as $l) {
                    // OpenCart stores names HTML-encoded ("Phones &amp; PDAs"); decode for a clean spreadsheet
                    $line[] = isset($r[$l]) && $r[$l] !== null ? html_entity_decode((string)$r[$l], ENT_QUOTES, 'UTF-8') : '';
                }

                Csv::put($out, $line);
            }
        } while (count($rows) === 1000);

        fclose($out);
    }

    /** Verify the link, apply a light per-token limit, then stream the right file with download headers. */
    public static function serve(Context $c, string $token, string $key): void {
        $payload = self::verify($token, $key);

        if (!$payload || !in_array($payload['e'] ?? '', self::ENTITIES, true)) {
            self::fail(410, 'This download link is invalid or has expired. Ask the assistant for a new one.');
            return;
        }

        $entity = (string)$payload['e'];

        // the Access tab may have changed since the link was made: re-check now so a closed section never leaks
        $denial = self::denial($c, $entity);

        if ($denial !== '') {
            self::fail(403, 'The store owner has closed this section; the export is no longer available.');
            return;
        }

        if ($c->rateLimiter && !$c->rateLimiter->hit('export:' . substr(hash('sha256', $token), 0, 24), 20)) {
            self::fail(429, 'This link was downloaded too many times in a short time. Try again in a minute.');
            return;
        }

        $file = self::filename($c->storeName, $entity);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $file . '"');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }

        self::stream($c, $entity, is_array($payload['f'] ?? null) ? $payload['f'] : []);
    }

    public static function filename(string $storeName, string $entity): string {
        return str_replace('_mcp-log_', '_' . $entity . '_', LogExport::filename($storeName, 'csv'));
    }

    // ------------------------------------------------------------------ internals

    /** Output columns as label => SQL expression, dropping hidden columns and friendly joins to closed tables. */
    private static function selectColumns(Context $c, string $entity): array {
        $db = $c->db;
        $table = self::TABLE[$entity];
        $have = [];

        foreach ($db->rows("SHOW COLUMNS FROM " . $db->t($table)) as $r) {
            $have[$r['Field']] = true;
        }

        $hidden = array_flip($c->policy()->hiddenColumns($table));
        $cols = [];

        foreach (self::COLUMNS[$entity] as $col) {
            if ($col === 'store_name') { // orders: joined below, not a column of `order` everywhere
                continue;
            }

            if (isset($have[$col]) && !isset($hidden[$col])) {
                $cols[$col] = 'm.`' . $col . '`';
            }
        }

        $L = (int)$c->languageId;

        if ($entity === 'products') {
            if ($c->policy()->canRead('product_description')) {
                $cols['name'] = "(SELECT pd.`name` FROM " . $db->t('product_description') . " pd WHERE pd.`product_id` = m.`product_id` ORDER BY pd.`language_id` = " . $L . " DESC LIMIT 1)"; // store language, else any
            }

            if (isset($have['manufacturer_id']) && $c->policy()->canRead('manufacturer')) {
                $cols['manufacturer'] = "(SELECT mf.`name` FROM " . $db->t('manufacturer') . " mf WHERE mf.`manufacturer_id` = m.`manufacturer_id` LIMIT 1)";
            }

            if ($c->hasTable('product_to_category') && $c->policy()->canRead('product_to_category') && $c->policy()->canRead('category_description')) {
                $cols['categories'] = "(SELECT GROUP_CONCAT(cd.`name` SEPARATOR '; ') FROM " . $db->t('product_to_category') . " p2c JOIN " . $db->t('category_description') . " cd ON cd.`category_id` = p2c.`category_id` AND cd.`language_id` = " . $L . " WHERE p2c.`product_id` = m.`product_id`)";
            }
        } elseif ($entity === 'orders') {
            if ($c->policy()->canRead('order_status')) {
                $cols['status'] = "(SELECT os.`name` FROM " . $db->t('order_status') . " os WHERE os.`order_status_id` = m.`order_status_id` AND os.`language_id` = " . $L . " LIMIT 1)";
            }
        } elseif ($entity === 'customers') {
            if (isset($have['customer_group_id']) && $c->hasTable('customer_group') && $c->policy()->canRead('customer_group')) {
                $cols['customer_group'] = "(SELECT cgd.`name` FROM " . $db->t('customer_group_description') . " cgd WHERE cgd.`customer_group_id` = m.`customer_group_id` AND cgd.`language_id` = " . $L . " LIMIT 1)";
            }
        }

        return $cols;
    }

    /** @return array [from clause, where clause, id expression] */
    /** Filters each entity's export takes. */
    const FILTERS = ['products' => ['category_id', 'manufacturer_id', 'status', 'store_id'], 'orders' => ['order_status_id', 'date_from', 'date_to', 'store_id'], 'customers' => ['customer_group_id', 'newsletter', 'store_id']];

    private static function query(Context $c, string $entity, array $filters): array {
        Tool::onlyKeys($filters, self::FILTERS[$entity], 'filters'); // an ignored filter would export everything
        $db = $c->db;
        $table = self::TABLE[$entity];
        $from = $db->t($table) . " m";
        $id = 'm.`' . self::TABLE[$entity] . '_id`';
        $w = [];

        if (isset($filters['store_id']) && $filters['store_id'] !== '' && $filters['store_id'] !== null) {
            $sid = (int)$filters['store_id'];
            $w[] = $entity === 'products'
                ? "m.`product_id` IN (SELECT `product_id` FROM " . $db->t('product_to_store') . " WHERE `store_id` = " . $sid . ")"
                : "m.`store_id` = " . $sid;
        }

        if ($entity === 'products') {
            if (!empty($filters['status']) || (isset($filters['status']) && $filters['status'] === false)) {
                $w[] = "m.`status` = " . ((int)(bool)$filters['status']);
            }

            if (!empty($filters['manufacturer_id'])) {
                $w[] = "m.`manufacturer_id` = " . (int)$filters['manufacturer_id'];
            }

            if (!empty($filters['category_id']) && $c->hasTable('product_to_category')) {
                $w[] = "m.`product_id` IN (SELECT `product_id` FROM " . $db->t('product_to_category') . " WHERE `category_id` = " . (int)$filters['category_id'] . ")";
            }
        } elseif ($entity === 'orders') {
            $w[] = "m.`order_status_id` > 0"; // skip the abandoned "missing" orders by default
            if (!empty($filters['order_status_id'])) {
                $w[] = "m.`order_status_id` = " . (int)$filters['order_status_id'];
            }

            foreach (['date_from' => '>=', 'date_to' => '<='] as $k => $op) {
                if (!empty($filters[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters[$k])) {
                    $w[] = "DATE(m.`date_added`) " . $op . " " . $db->q((string)$filters[$k]);
                }
            }
        } elseif ($entity === 'customers') {
            if (!empty($filters['customer_group_id'])) {
                $w[] = "m.`customer_group_id` = " . (int)$filters['customer_group_id'];
            }

            if (isset($filters['newsletter'])) {
                $w[] = "m.`newsletter` = " . ((int)(bool)$filters['newsletter']);
            }
        }

        return [$from, implode(' AND ', $w), $id];
    }

    private static function fail(int $status, string $message): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo $message;
    }

    private static function b64(string $s): string {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string {
        return (string)base64_decode(strtr($s, '-_', '+/'));
    }
}
