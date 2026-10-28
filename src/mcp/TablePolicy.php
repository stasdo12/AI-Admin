<?php
namespace OcMcp;

/**
 * Per-table access levels for the AI assistant.
 *
 *   none  - closed: not readable, not writable (default for credentials, sessions, secrets)
 *   read  - green : SELECT only (default for every other table)
 *   write - yellow: SELECT + UPDATE/INSERT, optionally limited to a list of columns
 *   full  - red   : everything incl. DELETE / REPLACE, all columns
 *
 * The store admin assigns levels per table (and writable columns) in the extension settings;
 * `recommended()` is the preset derived from the OpenCart 3 database dictionary (docs/*.xlsx).
 * Credential columns listed in HIDDEN_COLUMNS are never readable or writable through raw SQL, whatever the level.
 * Dedicated tools (update_product, update_order_status, ...) ask the policy for their own table before writing.
 */
final class TablePolicy {
    const NONE  = 'none';
    const READ  = 'read';
    const WRITE = 'write';
    const FULL  = 'full';
    const LEVELS = [self::NONE, self::READ, self::WRITE, self::FULL];

    /** Closed by default: credentials, sessions, secrets, admin rights. */
    const SENSITIVE_TABLES = [
        'user', 'user_group', 'user_authorize', 'user_login', 'user_token', 'session', 'api', 'api_ip', 'api_session', 'api_history',
        'customer_login', 'customer_token', 'customer_authorize',
        'upload', 'download', 'download_description', 'download_report', 'mcp_oauth_client', 'mcp_oauth_code', 'mcp_oauth_token',
    ];

    /**
     * Hard caps that no preset and no admin setting can lift (table => highest allowed level).
     * Extensions and design are never available; the audit tables are readable but never writable.
     */
    const LOCKED = [
        'extension' => 'none', 'extension_install' => 'none', 'extension_path' => 'none', 'modification' => 'none',
        'event' => 'none', 'startup' => 'none', 'cron' => 'none', 'module' => 'none', 'notification' => 'none',
        'layout' => 'none', 'layout_module' => 'none', 'layout_route' => 'none', 'theme' => 'none', 'translation' => 'none',
        'banner' => 'none', 'banner_image' => 'none',
        'mcp_log' => 'read', 'mcp_log_archive' => 'read', 'mcp_log_anchor' => 'none', 'mcp_log_archive_set' => 'none', 'mcp_change' => 'read', 'mcp_rate' => 'none', 'mcp_idem' => 'none',
        'mcp_image' => 'read', 'mcp_upload' => 'none', 'mcp_image_trash' => 'none', // imports go through import_image; upload links hold token hashes
        'mcp_prompt' => 'read', 'mcp_prompt_section' => 'read', // the owner's command library: read through list_prompts, written on the Prompts tab
        'setting' => 'write', // only through get_settings / update_settings (SettingsPolicy); raw SQL stays closed
    ];

    /** Tables never reachable through raw SQL, only through their dedicated tools (plus every mcp_* table: journal, log, prompts, images). */
    const RAW_SQL_CLOSED = ['setting', 'mcp_mail'];

    public static function rawSqlClosed(string $short): bool {
        return in_array($short, self::RAW_SQL_CLOSED, true) || strpos($short, 'mcp_') === 0;
    }

    /** Written by every tool next to the fields it changes; never a field of the Details dialog, so never part of a column restriction. */
    const COMPANION_COLUMNS = ['date_added', 'date_modified'];

    /** Values masked in every answer (codes that are worth money). */
    const MASKED_COLUMNS = ['voucher' => ['code'], 'order_voucher' => ['code']];

    /**
     * What a record is called (the Access tab marks them with a star): while the AI sees or changes any other field of a
     * record it sees these too, so it knows which record it is. Mirrors ANCHOR_COLS of the admin page; credentials excepted.
     */
    const ANCHOR_COLUMNS = ['name', 'title', 'model', 'code', 'firstname', 'lastname'];

    /** @var array<string,string[]> table => the columns the store's own admin form requires (saved by the admin from the form templates) */
    private $required = [];

    /** Never exposed through raw SQL, whatever the table level. */
    const HIDDEN_COLUMNS = [
        'customer' => ['password', 'salt', 'token', 'code'],
        'cart'     => ['session_id', 'api_id'],
    ];

    /** The working tables of the store: the "Full" preset opens these (keys only; the columns are an old list of the working fields). */
    const RECOMMENDED_WRITE = [
        'product'                 => ['model', 'sku', 'upc', 'ean', 'jan', 'isbn', 'mpn', 'location', 'quantity', 'stock_status_id', 'image', 'manufacturer_id', 'shipping', 'price', 'points', 'tax_class_id', 'date_available', 'weight', 'weight_class_id', 'length', 'width', 'height', 'length_class_id', 'subtract', 'minimum', 'sort_order', 'status', 'date_modified'],
        'product_description'     => ['name', 'description', 'tag', 'meta_title', 'meta_description', 'meta_keyword'],
        'product_special'         => [],
        'product_discount'        => [],
        'product_to_category'     => [],
        'product_to_store'        => [],
        'product_image'           => [],
        'product_attribute'       => [],
        'product_option'          => [],
        'product_option_value'    => [],
        'product_reward'          => [],
        'product_related'         => [],
        'product_filter'          => [],
        'product_code'            => [],
        'category'                => ['image', 'parent_id', 'top', 'column', 'sort_order', 'status', 'date_modified'],
        'category_description'    => ['name', 'description', 'meta_title', 'meta_description', 'meta_keyword'],
        'category_to_store'       => [],
        'category_filter'         => [],
        'manufacturer'            => ['name', 'image', 'sort_order'],
        'manufacturer_to_store'   => [],
        'attribute'               => [], 'attribute_description' => [], 'attribute_group' => [], 'attribute_group_description' => [],
        'option'                  => [], 'option_description' => [], 'option_value' => [], 'option_value_description' => [],
        'filter'                  => [], 'filter_description' => [], 'filter_group' => [], 'filter_group_description' => [],
        'information_description' => ['title', 'description', 'meta_title', 'meta_description', 'meta_keyword'],
        'information'             => ['bottom', 'sort_order', 'status'],
        'seo_url'                 => ['keyword'],
        'review'                  => ['status'],
        'order'                   => ['order_status_id'],
        'return'                  => [], 'return_history' => [],
        'voucher'                 => [], 'voucher_theme' => [], 'voucher_theme_description' => [],
        'customer'                => [], 'address' => [], 'customer_group' => [], 'customer_group_description' => [],
        'coupon'                  => [], 'coupon_product' => [], 'coupon_category' => [],
        'marketing'               => [],
        'setting'                 => SettingsPolicy::EDITABLE,
        'mcp_mail'                => [],
    ];

    /** @var string */
    private $prefix;
    /** @var array<string,array{level:string,columns:string[]}> unprefixed table => rule */
    private $acl = [];
    /** @var callable|null function(string $table): string[] column names, for the required-field rule */
    private $columns = null;
    /** @var array<string,string[]> memoized hidden columns per table */
    private $hiddenCache = [];

    /**
     * @param array|string|null $acl table => ['level' => ..., 'columns' => [...]] (array or JSON); table names with or without prefix
     */
    public function __construct(string $prefix, $acl = null) {
        $this->prefix = strtolower($prefix);

        if (is_string($acl) && $acl !== '') {
            $acl = json_decode($acl, true);
        }

        if (is_array($acl)) {
            foreach ($acl as $table => $rule) {
                $level = is_array($rule) ? ($rule['level'] ?? null) : $rule;

                if (!is_string($level) || !in_array($level, self::LEVELS, true)) {
                    continue;
                }

                $clean = function ($list) {
                    return array_values(array_unique(array_filter(array_map(function ($c) {
                        return strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string)$c));
                    }, is_array($list) ? $list : []))));
                };

                $short = $this->normalize((string)$table);
                $this->acl[$short] = [
                    'level'   => self::cap($short, $level),
                    'columns' => $clean(is_array($rule) ? ($rule['columns'] ?? []) : []),
                    'hidden'  => $clean(is_array($rule) ? ($rule['hidden'] ?? []) : []),
                ];
            }
        }
    }

    const PROFILES = ['read', 'write', 'full', 'all'];

    /**
     * Access profiles of the Access tab, by the parts of the admin menu (AccessMap::SECTIONS):
     * a section sets its parts, a part ("section.sub") overrides its section, a table ("table:name") overrides its part.
     * The service tables of System are not in the owner's menu and keep their own levels; credentials and locked tables are capped afterwards.
     */
    const PROFILE_PARTS = [
        'read' => [ // "Safe"
            'catalog' => self::READ, 'catalog.reviews' => self::NONE,
            'cms' => self::READ,
            'sales' => self::NONE,
            'customers' => self::NONE, 'customers.groups' => self::READ,
            'marketing' => self::READ, 'marketing.coupons' => self::NONE, 'marketing.mail' => self::NONE,
            'system' => self::NONE, // settings, users, API: nothing on Safe
            'localisation' => self::READ,
            'reports' => self::READ,
        ],
        'write' => [ // "Recommended"
            'catalog' => self::WRITE,
            'cms' => self::WRITE,
            'sales' => self::READ,
            'customers' => self::READ,
            'marketing' => self::WRITE,
            'system' => self::WRITE,
            'localisation' => self::READ,
            'reports' => self::READ,
        ],
    ];

    /**
     * Presets: table => rule.
     *  - read : the "Safe" profile of PROFILE_PARTS
     *  - write: the "Recommended" profile of PROFILE_PARTS; an editable part is editable in every field (settings still keep SettingsPolicy::canEditKey)
     *  - full : credentials closed, working tables full access (incl. DELETE), everything else read-only; locked tables stay locked
     */
    public static function recommended(string $profile = 'write'): array {
        $out = [];

        foreach (self::LOCKED as $t => $max) {
            $out[$t] = ['level' => $max, 'columns' => []];
        }

        if (isset(self::PROFILE_PARTS[$profile])) {
            $parts = self::PROFILE_PARTS[$profile];

            foreach (AccessMap::SECTIONS as $section => $def) {
                foreach ($def['subs'] as $sub => $part) {
                    if ($section . '.' . $sub === 'system.service') {
                        continue;
                    }

                    $level = $parts[$section . '.' . $sub] ?? $parts[$section] ?? null;

                    foreach ($part['tables'] as $t) {
                        if (!isset($parts['table:' . $t]) && $level === null) {
                            continue;
                        }

                        $lv = self::cap($t, $parts['table:' . $t] ?? $level);
                        $out[$t] = ['level' => $lv, 'columns' => []];
                    }
                }
            }
        } elseif ($profile === 'full' || $profile === 'all') {
            foreach (self::RECOMMENDED_WRITE as $t => $columns) {
                $out[$t] = ['level' => self::cap($t, self::FULL), 'columns' => []];
            }
        }

        foreach (self::SENSITIVE_TABLES as $t) {
            $out[$t] = ['level' => self::NONE, 'columns' => []];
        }

        return $out;
    }

    /** Level for stock tables a profile does not list explicitly (third-party tables stay closed in every preset). */
    public static function recommendedDefault(string $profile = 'write'): string {
        return $profile === 'all' ? self::FULL : self::READ; // "full access": every table that is not locked or a credential
    }

    /** All three presets in one structure for the admin UI. */
    public static function presets(): array {
        $out = [];

        foreach (self::PROFILES as $profile) {
            $out[$profile] = ['rules' => self::recommended($profile), 'default' => self::recommendedDefault($profile)];
        }

        return $out;
    }

    /** Highest level an admin may assign to a table. */
    public static function maxLevel(string $table): string {
        if (isset(self::LOCKED[$table])) {
            return self::LOCKED[$table];
        }

        return self::isSensitive($table) ? self::NONE : self::FULL;
    }

    /** Clamp a level to the table's cap. */
    public static function cap(string $table, string $level): string {
        $max = self::maxLevel($table);

        return array_search($level, self::LEVELS, true) > array_search($max, self::LEVELS, true) ? $max : $level;
    }

    /**
     * Access mode derived from the Access tab (the admin no longer picks it separately):
     * read_only when no table allows editing, otherwise manage (raw SQL writes were removed on 2026-10-09).
     * @param array $acl table => ['level' => ...] as saved by the admin page
     */
    public static function deriveMode(array $acl): string {
        foreach ($acl as $table => $rule) {
            $level = is_array($rule) && isset($rule['level']) ? (string)$rule['level'] : '';
            $t = strtolower((string)$table);

            if (($level === self::WRITE || $level === self::FULL) && (!isset(self::LOCKED[$t]) || self::LOCKED[$t] === self::WRITE)) {
                return 'manage';
            }
        }

        return 'read_only';
    }

    public static function isSensitive(string $table): bool {
        return in_array($table, self::SENSITIVE_TABLES, true);
    }

    /** Tables whose text fields are written by store visitors (reviews, comments, names, addresses): untrusted content. */
    const VISITOR_TEXT_TABLES = ['review', 'order', 'order_history', 'customer', 'address', 'return', 'return_history', 'customer_history', 'gdpr', 'affiliate'];

    /** Tables whose rows identify a person (name, e-mail, phone, address): counted against the hourly personal-data budget. */
    const IDENTITY_TABLES = ['customer', 'address', 'order', 'return', 'customer_ip', 'gdpr', 'affiliate', 'customer_affiliate', 'mcp_mail'];

    public static function hasIdentity(string $table): bool {
        return in_array($table, self::IDENTITY_TABLES, true);
    }

    public static function hasVisitorText(string $table): bool {
        return in_array($table, self::VISITOR_TEXT_TABLES, true);
    }

    /** Lower-case, unquoted, unprefixed table name. */
    public function normalize(string $table): string {
        $t = strtolower(trim($table, " \t\n\r`'\""));

        if (($pos = strrpos($t, '.')) !== false) {
            $t = trim(substr($t, $pos + 1), '`');
        }

        if ($this->prefix !== '' && strpos($t, $this->prefix) === 0) {
            $t = substr($t, strlen($this->prefix));
        }

        return $t;
    }

    public function rule(string $table): array {
        $t = $this->normalize($table);

        if (isset($this->acl[$t])) {
            return $this->acl[$t];
        }

        // stock OpenCart tables are readable by default; tables of third-party modules stay closed until the owner opens them
        return ['level' => self::cap($t, AccessMap::isKnown($t) ? self::READ : self::NONE), 'columns' => [], 'hidden' => []];
    }

    public function level(string $table): string {
        return $this->rule($table)['level'];
    }

    /** Writable columns at level write ([] = all). */
    public function writableColumns(string $table): array {
        return $this->rule($table)['columns'];
    }

    public function canRead(string $table): bool {
        return $this->level($table) !== self::NONE;
    }

    public function canDelete(string $table): bool {
        return $this->level($table) === self::FULL;
    }

    /**
     * May the given columns be written? Empty $columns = "some write, columns unknown":
     * allowed only when the table has no column restriction.
     */
    public function canWrite(string $table, array $columns = []): bool {
        $rule = $this->rule($table);

        if ($rule['level'] === self::FULL) {
            return !$this->touchesHidden($table, $columns);
        }

        if ($rule['level'] !== self::WRITE) {
            return false;
        }

        if ($this->touchesHidden($table, $columns)) {
            return false;
        }

        if (!$rule['columns']) {
            return true;
        }

        $columns = array_diff(array_map('strtolower', $columns), self::COMPANION_COLUMNS);

        if (!$columns) {
            return false;
        }

        foreach ($columns as $c) {
            if (!in_array($c, $rule['columns'], true)) {
                return false;
            }
        }

        return true;
    }

    /** Columns that are blocked for a write on this table (for error messages). */
    public function blockedColumns(string $table, array $columns): array {
        $rule = $this->rule($table);
        $hidden = $this->hiddenColumns($table);
        $out = [];

        foreach ($columns as $c) {
            $lc = strtolower($c);

            if (in_array($lc, $hidden, true) || ($rule['level'] === self::WRITE && $rule['columns'] && !in_array($lc, $rule['columns'], true) && !in_array($lc, self::COMPANION_COLUMNS, true))) {
                $out[] = $lc;
            }
        }

        return $out;
    }

    /**
     * The fields the store's own forms mark required, table => columns (array or the JSON the admin saves as
     * module_mcp_server_required). At FULL the AI creates records, so these stay visible and writable whatever the owner unticked.
     * @param array|string|null $map
     */
    public function setRequired($map): self {
        $this->required = [];
        $this->hiddenCache = [];

        if (is_string($map) && $map !== '') {
            $map = json_decode($map, true);
        }

        if (is_array($map)) {
            foreach ($map as $table => $cols) {
                if (is_array($cols) && $cols) {
                    $this->required[$this->normalize((string)$table)] = array_values(array_unique(array_map('strtolower', array_map('strval', $cols))));
                }
            }
        }

        return $this;
    }

    /** @return string[] */
    public function required(string $table): array {
        return $this->required[$this->normalize($table)] ?? [];
    }

    /** Lets the policy look up a table's columns (Context does), so the required-field rule can be applied. */
    public function setColumns(callable $columns): void {
        $this->columns = $columns;
        $this->hiddenCache = [];
    }

    public function hasColumns(): bool {
        return $this->columns !== null;
    }

    /**
     * Columns the AI can never see: credentials plus the fields the owner unticked under "read" - except the record's name
     * (ANCHOR_COLUMNS) while any other field of the record is visible, and the form's required fields (setRequired) while
     * the table is at FULL: the admin page never lets those be unticked then, and the server keeps the same rules for
     * settings saved earlier or written by hand.
     * @return string[]
     */
    public function hiddenColumns(string $table): array {
        $t = $this->normalize($table);

        if (isset($this->hiddenCache[$t])) {
            return $this->hiddenCache[$t];
        }

        $credentials = self::HIDDEN_COLUMNS[$t] ?? [];
        $hidden = array_values(array_unique(array_merge($credentials, $this->acl[$t]['hidden'] ?? [])));

        if ($this->level($t) === self::FULL && isset($this->required[$t])) {
            $hidden = array_values(array_diff($hidden, array_diff($this->required[$t], $credentials))); // the AI creates records here: the form's required fields stay open
        }

        $anchors = array_diff(array_intersect($hidden, self::ANCHOR_COLUMNS), $credentials);

        if ($anchors && $this->columns !== null && $this->level($t) !== self::NONE) {
            $cols = array_map('strtolower', (array)call_user_func($this->columns, $t));
            $othersVisible = false;

            foreach ($cols as $c) { // a field the owner could tick: not a key, a date or a sort order (those are never shown as fields)
                if (!in_array($c, $hidden, true) && !in_array($c, self::ANCHOR_COLUMNS, true) && substr($c, -3) !== '_id' && !in_array($c, ['date_added', 'date_modified', 'sort_order'], true)) {
                    $othersVisible = true;
                    break;
                }
            }

            if ($othersVisible) {
                $hidden = array_values(array_diff($hidden, $anchors));
            }
        }

        return $this->hiddenCache[$t] = $hidden;
    }

    /** Credential columns only (password, salt, tokens) - never visible, not configurable. */
    public function credentialColumns(string $table): array {
        return self::HIDDEN_COLUMNS[$this->normalize($table)] ?? [];
    }

    /** Owner-hidden columns of several tables (for filtering dedicated tool output). */
    public function hiddenFor(array $tables): array {
        $out = [];

        foreach ($tables as $t) {
            foreach ($this->hiddenColumns($t) as $c) {
                $out[$c] = true;
            }
        }

        return array_keys($out);
    }

    public function columnLevel(string $table, string $column): string {
        if (in_array(strtolower($column), $this->hiddenColumns($table), true)) {
            return self::NONE;
        }

        $rule = $this->rule($table);

        if ($rule['level'] === self::WRITE && $rule['columns'] && !in_array(strtolower($column), $rule['columns'], true)) {
            return self::READ;
        }

        return $rule['level'];
    }

    private function touchesHidden(string $table, array $columns): bool {
        $hidden = $this->hiddenColumns($table);

        foreach ($columns as $c) {
            if (in_array(strtolower($c), $hidden, true)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ SQL inspection

    /** Tables referenced by a statement (identifiers as written, de-duplicated). */
    public function tablesIn(string $sql): array {
        $s = Tools\SqlGuard::identifiersOnly($sql);
        $found = [];

        if (preg_match('/^\s*(?:DESCRIBE|DESC|EXPLAIN|SHOW\s+(?:FULL\s+)?(?:COLUMNS|FIELDS|INDEX|INDEXES|KEYS)\s+FROM)\s+(`?[\w]+`?(?:\s*\.\s*`?[\w]+`?)?)/i', $s, $m)) {
            $found[] = $m[1];
        }

        $stop = '(?=\bWHERE\b|\bSET\b|\bON\b|\bUSING\b|\bLEFT\b|\bRIGHT\b|\bINNER\b|\bOUTER\b|\bCROSS\b|\bNATURAL\b|\bSTRAIGHT_JOIN\b|\bJOIN\b|\bVALUES\b|\bVALUE\b|\bSELECT\b|\bGROUP\b|\bORDER\b|\bLIMIT\b|\bHAVING\b|\bUNION\b|\bFOR\b|\bLOCK\b|\bPARTITION\b|\(|\)|;|$)';

        if (preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO|TABLE)\b\s*(.*?)' . $stop . '/is', $s, $mm)) {
            foreach ($mm[1] as $chunk) {
                foreach (explode(',', $chunk) as $part) {
                    $part = trim($part);

                    if ($part === '' || strtoupper($part) === 'DUAL') {
                        continue;
                    }

                    if (preg_match('/^(`?[\w]+`?(?:\s*\.\s*`?[\w]+`?)?)/', $part, $m)) {
                        $found[] = $m[1];
                    }
                }
            }
        }

        // Parentheses, sub-selects and odd spacing can hide a table from the FROM/JOIN parser above, so additionally
        // every identifier carrying the store table prefix counts as a referenced table. Without a prefix, the names of
        // closed tables are matched as whole words instead (may over-block a same-named column, never under-blocks).
        if ($this->prefix !== '') {
            if (preg_match_all('/(?<![\w$])(' . preg_quote($this->prefix, '/') . '[A-Za-z0-9_$]+)(?![\w$])/i', $s, $pm)) {
                foreach ($pm[1] as $t) {
                    $found[] = $t;
                }
            }
        } else {
            foreach (array_merge(self::SENSITIVE_TABLES, array_keys(self::LOCKED), self::RAW_SQL_CLOSED) as $t) {
                if (preg_match('/(?<![\w$.])`?' . preg_quote($t, '/') . '`?(?![\w$])/i', $s)) {
                    $found[] = $t;
                }
            }
        }

        $out = [];

        foreach ($found as $t) {
            $t = str_replace('`', '', preg_replace('/\s+/', '', $t));

            if (stripos($t, 'information_schema.') === 0) {
                continue;
            }

            $out[strtolower($t)] = $t;
        }

        return array_values($out);
    }

    /** Columns assigned by an UPDATE ... SET / INSERT ... (cols) / INSERT ... SET statement; null when they cannot be determined. */
    public static function writtenColumns(string $sql): ?array {
        $s = Tools\SqlGuard::identifiersOnly($sql);
        $first = strtoupper((string)preg_replace('/^\s*([A-Za-z]+).*/s', '$1', $s));
        $cols = [];

        if ($first === 'UPDATE' || (($first === 'INSERT' || $first === 'REPLACE') && preg_match('/\bSET\b/i', $s) && !preg_match('/\bVALUES?\b/i', $s))) {
            if (!preg_match('/\bSET\b/i', $s, $m, PREG_OFFSET_CAPTURE)) {
                return null;
            }

            // scan the assignment list: split on depth-0 commas, stop at a depth-0 WHERE / ORDER / LIMIT
            $rest = substr($s, $m[0][1] + strlen($m[0][0]));
            $depth = 0;
            $buf = '';
            $parts = [];
            $len = strlen($rest);

            for ($i = 0; $i < $len; $i++) {
                $ch = $rest[$i];

                if ($depth === 0 && ($i === 0 || ctype_space($rest[$i - 1])) && preg_match('/^(WHERE|ORDER|LIMIT)\b/i', substr($rest, $i, 6))) {
                    break;
                }

                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                }

                if ($ch === ',' && $depth === 0) {
                    $parts[] = $buf;
                    $buf = '';
                } else {
                    $buf .= $ch;
                }
            }

            $parts[] = $buf;

            foreach ($parts as $p) {
                if (preg_match('/^\s*(?:`?[\w]+`?\s*\.\s*)?`?([\w]+)`?\s*=/', $p, $cm)) {
                    $cols[] = strtolower($cm[1]);
                } elseif (trim($p) !== '') {
                    return null;
                }
            }

            return $cols;
        }

        if ($first === 'INSERT' || $first === 'REPLACE') {
            if (preg_match('/\bINTO\s+`?[\w]+`?(?:\s*\.\s*`?[\w]+`?)?\s*\(([^)]*)\)/i', $s, $m)) {
                foreach (explode(',', $m[1]) as $c) {
                    $c = trim(str_replace('`', '', $c));

                    if ($c !== '') {
                        $cols[] = strtolower($c);
                    }
                }

                return $cols;
            }

            return null; // INSERT without a column list or INSERT ... SELECT
        }

        return [];
    }

    /** @var array|null structured details of the last denial (see AccessDenied) */
    private $lastDenial = null;

    public function lastDenial(): ?array {
        return $this->lastDenial;
    }

    private function deny(array $info): string {
        $this->lastDenial = $info;

        return AccessDenied::text($info);
    }

    private static function rawClosed(string $table): array {
        $info = AccessDenied::table($table, 'access the table ' . $table . ' with SQL', 'none', self::READ);
        $info['kind'] = 'locked';
        $info['can_be_granted'] = false;
        $info['how_to_grant'] = $table === 'setting'
            ? 'This cannot be enabled: raw SQL on settings is never allowed because the table also holds passwords and payment keys. Use the tools get_settings and update_settings instead; which settings they may change is set under Access → System → Settings.'
            : 'This cannot be enabled: the module\'s own tables (change journal, call log, mail queue, prompts, images) are never read with SQL, because their records can hold data of parts that are closed now. Use list_changes, list_mails, list_prompts and list_images instead.';

        return $info;
    }

    /** Validate a read statement. Returns the unified denial text or null when allowed (details: lastDenial()). */
    public function checkRead(string $sql, array &$tables = []): ?string {
        $this->lastDenial = null;
        $tables = $this->tablesIn($sql);
        $s = strtolower(Tools\SqlGuard::identifiersOnly($sql));

        foreach ($tables as $t) {
            $short = $this->normalize($t);

            if (self::rawSqlClosed($short)) {
                return $this->deny(self::rawClosed($short));
            }

            if (!$this->canRead($t)) {
                return $this->deny(AccessDenied::table($short, 'read data from the table ' . $short . ' (it is closed)', $this->level($t), self::READ));
            }

            foreach ($this->hiddenColumns($t) as $col) {
                if (preg_match('/\b' . preg_quote($col, '/') . '\b/', $s)) {
                    if (in_array($col, $this->credentialColumns($t), true)) {
                        $info = AccessDenied::table($short, 'read the credential field ' . $col . ' of ' . $short, $this->level($t), self::FULL, [$col], 'field');
                        $info['kind'] = 'locked';
                        $info['can_be_granted'] = false;
                        $info['how_to_grant'] = 'This cannot be enabled: password, salt, token and code fields are never available to the AI. Select other columns.';

                        return $this->deny($info);
                    }

                    return $this->deny(AccessDenied::table($short, 'read the field ' . $col . ' of ' . $short . ' (hidden by the store owner)', $this->level($t), self::READ, [$col], 'field'));
                }
            }

            // a masked column (voucher codes) may only be selected as it is: used in WHERE / ORDER BY, inside a function or under
            // an alias its value could be read or guessed around the mask
            foreach (self::MASKED_COLUMNS[$short] ?? [] as $col) {
                if (self::usedBeyondProjection($s, $col)) {
                    $info = AccessDenied::table($short, 'use the field ' . $col . ' of ' . $short . ' in a condition, sort, function or under another name', $this->level($t), self::READ, [$col], 'field');
                    $info['kind'] = 'locked';
                    $info['can_be_granted'] = false;
                    $info['how_to_grant'] = 'This cannot be enabled: ' . $col . ' is shown masked (****1234) and can only be selected as a plain column. Filter and sort by other fields.';

                    return $this->deny($info);
                }
            }
        }

        return null;
    }

    /** True when $col appears anywhere but as a bare item of the select list (after FROM, in a function, with an alias). */
    public static function usedBeyondProjection(string $s, string $col): bool {
        $w = '/\b' . preg_quote($col, '/') . '\b/';

        if (!preg_match($w, $s)) {
            return false;
        }

        $pos = preg_match('/\bfrom\b/', $s, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : strlen($s);
        $head = substr($s, 0, $pos);
        $tail = substr($s, $pos);

        if (preg_match($w, $tail)) {
            return true;
        }

        return preg_match('/\(\s*(?:distinct\s+)?(?:[\w`]+\s*\.\s*)?`?' . preg_quote($col, '/') . '`?\b/', $head) === 1 // inside a function: length(code), hex(code)
            || preg_match('/\b' . preg_quote($col, '/') . '`?\s+(?:as\s+)?[a-z_][\w]*/', $head) === 1 // code AS c / code c
            || preg_match('/\b' . preg_quote($col, '/') . '`?\s*[-+*\/|]/', $head) === 1; // code || '' / code + 0
    }


    public static function mask(string $value): string {
        $len = strlen($value);

        return $len <= 6 ? str_repeat('*', $len) : substr($value, 0, 4) . str_repeat('*', $len - 6) . substr($value, -2);
    }

    /** Mask money-like codes (gift voucher codes) in result rows. */
    public function maskRows(array $rows, array $tables): array {
        $cols = [];

        foreach ($tables as $t) {
            foreach (self::MASKED_COLUMNS[$this->normalize($t)] ?? [] as $c) {
                $cols[$c] = true;
            }
        }

        if (!$cols) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                if (isset($cols[strtolower((string)$k)]) && is_string($v) && $v !== '') {
                    $row[$k] = self::mask($v);
                }
            }
        }
        unset($row);

        return $rows;
    }

    /** Remove hidden columns from result rows (SELECT * on tables with credential columns). */
    public function stripHiddenColumns(array $rows, array $tables): array {
        $drop = [];

        foreach ($tables as $t) {
            foreach ($this->hiddenColumns($t) as $col) {
                $drop[$col] = true;
            }
        }

        if (!$drop || !$rows) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                if (isset($drop[strtolower((string)$k)])) {
                    unset($row[$k]);
                }
            }
        }
        unset($row);

        return $rows;
    }

    /** Machine-readable summary for store_info / instructions. */
    public function summary(): array {
        $levels = [self::NONE => [], self::READ => 0, self::WRITE => [], self::FULL => []];

        foreach ($this->acl as $t => $rule) {
            if ($rule['level'] === self::WRITE) {
                $levels[self::WRITE][$t] = $rule['columns'] ?: 'all';
            } elseif ($rule['level'] === self::FULL) {
                $levels[self::FULL][] = $t;
            } elseif ($rule['level'] === self::NONE) {
                $levels[self::NONE][] = $t;
            }
        }

        foreach (self::SENSITIVE_TABLES as $t) {
            if (!isset($this->acl[$t])) {
                $levels[self::NONE][] = $t;
            }
        }

        sort($levels[self::NONE]);
        sort($levels[self::FULL]);

        return [
            'default_level'   => 'read (sensitive tables: none)',
            'closed_tables'   => $levels[self::NONE],
            'writable_tables' => $levels[self::WRITE],
            'full_tables'     => $levels[self::FULL],
            'hidden_columns'  => self::HIDDEN_COLUMNS,
            'rules'           => [
                'none'  => 'not readable, not writable',
                'read'  => 'SELECT only',
                'write' => 'SELECT + UPDATE/INSERT, limited to the listed columns when a list is given',
                'full'  => 'everything incl. DELETE / REPLACE',
            ],
        ];
    }

    /** One-paragraph description for the server instructions. */
    public function describe(): string {
        $s = $this->summary();
        $writable = [];

        foreach ($s['writable_tables'] as $t => $cols) {
            $writable[] = $t . ($cols === 'all' ? '' : ' (' . implode(', ', array_slice((array)$cols, 0, 6)) . (count((array)$cols) > 6 ? ', ...' : '') . ')');
        }

        return 'Raw SQL access levels per table: closed = ' . (implode(', ', array_slice($s['closed_tables'], 0, 12)) ?: 'none')
            . (count($s['closed_tables']) > 12 ? ', ...' : '')
            . '; writable = ' . (implode('; ', array_slice($writable, 0, 12)) ?: 'none')
            . (count($writable) > 12 ? '; ...' : '')
            . '; full = ' . (implode(', ', $s['full_tables']) ?: 'none')
            . '; every other table is read-only. Credential columns (customer password/salt/token/code) are never readable. db_schema shows the level of each table.';
    }
}
