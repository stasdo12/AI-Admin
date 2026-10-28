<?php
namespace OcMcp;

/**
 * Everything a tool needs to know about the store it runs in.
 * Built by the OpenCart controller (3.x or 4.x) and passed to every tool call.
 */
final class Context {
    const MODE_READ_ONLY = 'read_only';
    const MODE_MANAGE    = 'manage';

    /** @var Db */
    public $db;
    /** @var object|null OpenCart cache (has delete()) */
    public $cache = null;
    /** @var string read_only | manage | developer */
    public $mode = self::MODE_READ_ONLY;
    /** @var string e.g. "3.0.4.1" or "4.1.0.4" */
    public $ocVersion = '';
    /** @var int */
    public $languageId = 1;
    /** @var int */
    public $storeId = 0;
    /** @var int */
    public $customerGroupId = 1;
    /** @var string ISO currency code of the store's default currency */
    public $currency = '';
    /** @var string */
    public $storeName = '';
    /** @var string */
    public $storeUrl = '';
    /** @var array clientInfo of the calling MCP client (name/version) */
    public $clientInfo = [];
    /** @var string */
    public $ip = '';
    /** @var Logger|null */
    public $logger = null;
    /**
     * Optional callable (order_id, order_status_id, comment, notify) => void that goes through
     * OpenCart's own order history model (stock, e-mails, events). Falls back to plain SQL when null.
     * @var callable|null
     */
    public $orderHistoryHandler = null;

    /** @var TablePolicy|null set by the controller; created with defaults on first use */
    public $policy = null;
    /** @var string URL of the OAuth protected-resource metadata ('' = OAuth disabled) */
    public $resourceMetadataUrl = '';
    /** @var array|null OAuth grant that authorized the current request */
    public $grant = null;
    /** @var RateLimiter|null */
    public $rateLimiter = null;
    /** @var ChangeJournal|null journal of the current tool call (set by Server) */
    public $journal = null;
    /** @var string|null the blog the call names (save_content blog: a label or table from the question), null = choose */
    public $blog = null;
    /** @var bool a write: with two blogs in use the AI must ask which one instead of the module choosing */
    public $blogStrict = false;
    /** @var int minutes before a delayed deletion is executed */
    public $deleteDelay = 10;
    /**
     * Optional callable (string $to, string $subject, string $html, string $text): void that sends one e-mail
     * through OpenCart's own mail settings (set by the controller).
     * @var callable|null
     */
    public $mailer = null;

    /** @var int e-mails per hour (0 = unlimited) */
    public $mailHourly = 200;

    /** @var int rows with customer identity data (names, e-mails, phones, addresses) one connection may receive per hour; 0 = unlimited */
    public $piiHourly = 2000;
    /** @var Mailer|null */
    private $mailerService = null;

    public function mailer(): Mailer {
        if ($this->mailerService === null) {
            $this->mailerService = new Mailer($this->db, $this->mailer, $this->rateLimiter ? new RateLimiter($this->db, 3600) : null, $this->mailHourly, $this->storeUrl);
        }

        return $this->mailerService;
    }

    public function journal(): ChangeJournal {
        if ($this->journal === null) {
            $this->journal = (new ChangeJournal($this->db, $this->clientName(), '', $this->deleteDelay))->setCaller(Idempotency::caller($this));
        }

        return $this->journal;
    }
    /** @var ContentPolicy|null */
    private $content = null;
    /** @var string OpenCart's image/ directory (DIR_IMAGE), '' when unknown */
    public $imageDir = '';
    /** @var string OpenCart's system/ directory (DIR_SYSTEM), to see whether its Image library handles WebP */
    public $systemDir = '';
    /** @var callable|null (string $token) => string URL of the phone upload page (set by the controller) */
    public $uploadUrl = null;
    /** @var callable|null builds the signed CSV download link from a token */
    public $downloadUrl = null;
    /** @var string OpenCart's writable storage folder (DIR_STORAGE); import files live in its mcp-import/ */
    public $storageDir = '';
    /** @var array|null create_order: ['address' => fn(country_id, zone_id), 'tax' => fn(value, tax_class_id): float, 'rates' => fn(value, tax_class_id): array, 'create' => fn(array data): int, 'history' => fn(order_id, status_id, comment, notify)] */
    public $orderApi = null;
    /** @var callable|null tests: (string $url) => string image bytes instead of a download */
    public $imageFetcher = null;

    /** The owner's prompt library, in the store language. */
    public function prompts(): PromptLibrary {
        return new PromptLibrary($this->db, PromptLibrary::lang((string)$this->config('config_language', 'en-gb')));
    }

    public function images(): ImageStore {
        $dir = $this->imageDir !== '' ? $this->imageDir : (defined('DIR_IMAGE') ? (string)constant('DIR_IMAGE') : '');

        if ($dir === '' || !is_dir($dir)) {
            throw new ToolException("The store's image folder is not available to the module.");
        }

        return new ImageStore($this->db, $dir, $this->content()->settings(), $this->journal(), $this->imageFetcher);
    }

    /** Content rules of the store (owner settings, own hosts, SEO links in the language of the text). */
    public function content(): ContentPolicy {
        if ($this->content === null) {
            $hosts = [$this->storeUrl, (string)$this->config('config_url', ''), (string)$this->config('config_ssl', '')];

            foreach (['HTTP_SERVER', 'HTTPS_SERVER', 'HTTP_CATALOG', 'HTTPS_CATALOG'] as $const) {
                if (defined($const)) {
                    $hosts[] = (string)constant($const);
                }
            }

            $links = new SeoLinks($this->db, $this->ocMajor(), $this->storeUrl, $this->storeId, (bool)$this->config('config_seo_url', false));
            $db = $this->db;
            $charsets = [];
            $mb4 = function (string $table, string $column) use ($db, &$charsets): bool {
                $key = $table . '.' . $column;

                if (!isset($charsets[$key])) {
                    $cs = $db->value("SELECT `CHARACTER_SET_NAME` FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = " . $db->q($db->prefix() . $table)
                        . " AND `COLUMN_NAME` = " . $db->q($column), 'CHARACTER_SET_NAME');
                    $charsets[$key] = $cs === null || stripos((string)$cs, 'utf8mb4') === 0 || stripos((string)$cs, 'utf16') === 0 || stripos((string)$cs, 'utf32') === 0;
                }

                return $charsets[$key];
            };
            $this->content = new ContentPolicy(ContentPolicy::normalize($this->config('module_mcp_server_content', '')), array_filter($hosts), [$links, 'resolve'], $mb4);
        }

        return $this->content;
    }

    /** @var int requests per minute per IP (0 = unlimited) */
    public $rateLimit = 300;

    /** @var callable (string $key) => mixed */
    private $configGetter;

    public function __construct(Db $db, callable $configGetter) {
        $this->db = $db;
        $this->configGetter = $configGetter;
    }

    public function config(string $key, $default = null) {
        $v = call_user_func($this->configGetter, $key);

        return $v === null ? $default : $v;
    }

    public function ocMajor(): int {
        $major = (int)substr($this->ocVersion, 0, 1);

        return $major > 0 ? $major : 3;
    }

    /** Is a tool of the given level (read|write|developer) allowed in the current mode? */
    /**
     * What the store allows, ignoring the limit of the current connection. tools/list uses this: clients such as Claude
     * load the tool list once when they connect, so a connection the owner later allows to change data in the admin
     * must already know the write tools; the connection limit is enforced on every tools/call instead.
     */
    public function can(string $level): bool {
        switch ($level) {
            case Tool::LEVEL_READ:
                return true;
            case Tool::LEVEL_WRITE:
                return $this->mode === self::MODE_MANAGE;
        }

        return false;
    }

    public function clearCache(string $key): void {
        if ($this->cache && method_exists($this->cache, 'delete')) {
            try {
                $this->cache->delete($key);
            } catch (\Throwable $e) {
                // cache problems must never break a tool call
            }
        }
    }

    /** @var array memoized schema probes */
    private $schemaCache = [];
    /** @var array values built once per request */
    private $memo = [];

    /** A value built once per request (the store map, for one). */
    public function memo(string $key, callable $make) {
        if (!array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $make();
        }

        return $this->memo[$key];
    }

    /** Does a row with this id exist? Memoized per request: a 5000-row price list asks about the same brand thousands of times. */
    public function exists(string $table, string $column, int $id): bool {
        return $this->memo("exists:$table:$column:$id", function () use ($table, $column, $id) { return $this->db->exists($table, $column, $id); });
    }

    /** Does a (prefixed) table exist? Memoized per request. */
    public function hasTable(string $table): bool {
        $key = 't:' . $table;

        if (!array_key_exists($key, $this->schemaCache)) {
            $this->schemaCache[$key] = $this->db->tableExists($table);
        }

        return $this->schemaCache[$key];
    }

    /** @var array|null store_id => ['name' => ..., 'url' => ...], the default store included */
    private $stores = null;

    /** Every store of this OpenCart: 0 = the default one, then the rows of the store table. */
    public function stores(): array {
        if ($this->stores === null) {
            $this->stores = [0 => ['name' => (string)$this->config('config_name', $this->storeName), 'url' => (string)$this->config('config_url', $this->storeUrl)]];

            if ($this->hasTable('store')) {
                foreach ($this->db->rows("SELECT `store_id`, `name`, `url` FROM " . $this->db->t('store') . " ORDER BY `store_id`") as $r) {
                    $this->stores[(int)$r['store_id']] = ['name' => (string)$r['name'], 'url' => (string)$r['url']];
                }
            }
        }

        return $this->stores;
    }

    /** More than one store: the store arguments of the tools are shown and used. */
    public function multiStore(): bool {
        return count($this->stores()) > 1;
    }

    /** One setting of one store (each store keeps its own config_* rows); the default store reads the loaded config. */
    public function storeConfig(int $storeId, string $key, $default = null) {
        if ($storeId === 0) {
            return $this->config($key, $default);
        }

        $r = $this->db->row("SELECT `value`, `serialized` FROM " . $this->db->t('setting') . " WHERE `store_id` = " . $storeId . " AND `key` = " . $this->db->q($key) . " LIMIT 1");

        if ($r === null) {
            return $this->config($key, $default); // not set for that store: OpenCart falls back to the default store too
        }

        return $r['serialized'] ? json_decode((string)$r['value'], true) : $r['value'];
    }

    /** Validated store ids from an argument: a list of ids that exist. */
    public function storeIds($given, string $what = 'stores'): array {
        $ids = array_values(array_unique(array_map('intval', is_array($given) ? $given : [$given])));
        $known = $this->stores();

        if (!$ids) {
            throw new ToolException("$what: give at least one store id (0 = the default store).");
        }

        if ($bad = array_diff($ids, array_keys($known))) {
            throw new ToolException("$what: no store " . implode(', ', $bad) . '. Stores: ' . implode(', ', array_map(function ($id, $s) { return $id . ' = ' . $s['name']; }, array_keys($known), $known)) . '.');
        }

        return $ids;
    }

    /** Does a column exist in a (prefixed) table? Memoized per request. */
    public function hasColumn(string $table, string $column): bool {
        $key = 'c:' . $table . '.' . $column;

        if (!array_key_exists($key, $this->schemaCache)) {
            $rows = $this->hasTable($table) ? $this->db->rows("SHOW COLUMNS FROM " . $this->db->t($table) . " LIKE '" . $this->db->esc($column) . "'") : [];
            $this->schemaCache[$key] = !empty($rows);
        }

        return $this->schemaCache[$key];
    }

    /**
     * SQL expression for the product SKU: OC <= 4.0 keeps it in product.sku, OC 4.1 moved codes to product_code.
     */
    public function skuExpr(string $alias = 'p'): string {
        if ($this->hasColumn('product', 'sku')) {
            return $alias . '.`sku`';
        }

        if ($this->hasTable('product_code')) {
            return "(SELECT pc.`value` FROM " . $this->db->t('product_code') . " pc WHERE pc.`product_id` = " . $alias . ".`product_id` AND pc.`code` = 'SKU' LIMIT 1)";
        }

        return "''";
    }

    /** SQL condition matching a LIKE pattern against product codes (sku/upc/ean...). */
    public function codeLikeCondition(string $likeLiteral, string $alias = 'p'): string {
        if ($this->hasColumn('product', 'sku')) {
            return $alias . ".`sku` LIKE " . $likeLiteral;
        }

        if ($this->hasTable('product_code')) {
            return "EXISTS (SELECT 1 FROM " . $this->db->t('product_code') . " pc WHERE pc.`product_id` = " . $alias . ".`product_id` AND pc.`value` LIKE " . $likeLiteral . ")";
        }

        return '0';
    }

    /** True when specials live in product_special (OC 3.x / 4.0.x); false when merged into product_discount (OC 4.1+). */
    public function hasSpecialTable(): bool {
        return $this->hasTable('product_special');
    }

    /**
     * SQL expression for the currently active special price of a product for the default customer group (or NULL).
     */
    public function specialExpr(string $alias = 'p'): string {
        $G = (int)$this->customerGroupId;
        $dates = "(ps.`date_start` IS NULL OR ps.`date_start` = '0000-00-00' OR ps.`date_start` < NOW()) AND (ps.`date_end` IS NULL OR ps.`date_end` = '0000-00-00' OR ps.`date_end` > NOW())";

        if ($this->hasSpecialTable()) {
            return "(SELECT ps.`price` FROM " . $this->db->t('product_special') . " ps WHERE ps.`product_id` = " . $alias . ".`product_id` AND ps.`customer_group_id` = " . $G
                . " AND " . $dates . " ORDER BY ps.`priority` ASC, ps.`price` ASC LIMIT 1)";
        }

        if ($this->hasColumn('product_discount', 'special')) {
            return "(SELECT (CASE WHEN ps.`type` = 'P' THEN (" . $alias . ".`price` - (" . $alias . ".`price` * (ps.`price` / 100))) WHEN ps.`type` = 'S' THEN (" . $alias . ".`price` - ps.`price`) ELSE ps.`price` END)"
                . " FROM " . $this->db->t('product_discount') . " ps WHERE ps.`product_id` = " . $alias . ".`product_id` AND ps.`customer_group_id` = " . $G . " AND ps.`special` = 1 AND ps.`quantity` = 1"
                . " AND " . $dates . " ORDER BY ps.`priority` ASC, ps.`price` ASC LIMIT 1)";
        }

        return 'NULL';
    }

    /** Column names of a (prefixed) table, [] when it does not exist. Memoized per request. */
    public function columnNames(string $table): array {
        $key = 'cols:' . $table;

        if (!array_key_exists($key, $this->schemaCache)) {
            $this->schemaCache[$key] = $this->hasTable($table) ? array_map(function ($r) { return $r['Field']; }, $this->db->rows("SHOW COLUMNS FROM " . $this->db->t($table))) : [];
        }

        return $this->schemaCache[$key];
    }

    public function policy(): TablePolicy {
        if ($this->policy === null) {
            $this->policy = new TablePolicy($this->db->prefix());
        }

        if (!$this->policy->hasColumns()) {
            $self = $this;
            $this->policy->setColumns(function (string $table) use ($self) { return $self->columnNames($table); });
        }

        return $this->policy;
    }

    /**
     * The same value with the secret URL keyword cut out of every string. The keyword also lives in seo_url,
     * which the catalog.seo section opens for reading: no tool answer may hand it to the AI.
     */
    public function hideSecrets($value) {
        $secret = trim((string)$this->config('module_mcp_server_url_secret', ''));

        if (strlen($secret) < 16) { // the admin accepts only 16+ characters: a shorter value is not a secret alias
            return $value;
        }

        if (is_string($value)) {
            return str_replace($secret, '[secret URL hidden]', $value);
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->hideSecrets($v);
            }
        }

        return $value;
    }

    public function clientName(): string {
        $name = isset($this->clientInfo['name']) ? (string)$this->clientInfo['name'] : '';
        $version = isset($this->clientInfo['version']) ? (string)$this->clientInfo['version'] : '';

        return trim($name . ' ' . $version);
    }
}
