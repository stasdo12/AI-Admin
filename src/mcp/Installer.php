<?php
namespace OcMcp;

/**
 * Schema management shared by the OC3 and OC4 admin controllers.
 */
final class Installer {
    /** @var Db */
    private $db;

    public function __construct(Db $db) {
        $this->db = $db;
    }

    public function install(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(Logger::TABLE) . " ("
            . " `mcp_log_id` INT(11) NOT NULL AUTO_INCREMENT,"
            . " `date_added` DATETIME NOT NULL,"
            . " `ip` VARCHAR(45) NOT NULL DEFAULT '',"
            . " `client` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `caller` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `tool` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `arguments` TEXT NOT NULL,"
            . " `status` VARCHAR(16) NOT NULL DEFAULT 'ok',"
            . " `message` TEXT NOT NULL,"
            . " `duration_ms` INT(11) NOT NULL DEFAULT 0,"
            . " `prev_hash` CHAR(64) NOT NULL DEFAULT '',"
            . " `hash` CHAR(64) NOT NULL DEFAULT '',"
            . " PRIMARY KEY (`mcp_log_id`),"
            . " KEY `date_added` (`date_added`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t('mcp_oauth_client') . " ("
            . " `client_id` VARCHAR(255) NOT NULL,"
            . " `client_secret_hash` CHAR(64) NULL,"
            . " `client_name` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `redirect_uris` TEXT NOT NULL,"
            . " `auth_method` VARCHAR(32) NOT NULL DEFAULT 'none',"
            . " `source` VARCHAR(16) NOT NULL DEFAULT 'dcr',"
            . " `metadata` TEXT NOT NULL,"
            . " `date_added` DATETIME NOT NULL,"
            . " `date_fetched` DATETIME NOT NULL,"
            . " PRIMARY KEY (`client_id`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t('mcp_oauth_code') . " ("
            . " `code_hash` CHAR(64) NOT NULL,"
            . " `client_id` VARCHAR(255) NOT NULL,"
            . " `redirect_uri` TEXT NOT NULL,"
            . " `code_challenge` VARCHAR(128) NOT NULL,"
            . " `scope` VARCHAR(255) NOT NULL DEFAULT '',"
            . " `resource` VARCHAR(255) NOT NULL DEFAULT '',"
            . " `user_id` INT(11) NOT NULL DEFAULT 0,"
            . " `username` VARCHAR(64) NOT NULL DEFAULT '',"
            . " `expires` DATETIME NOT NULL,"
            . " `used` TINYINT(1) NOT NULL DEFAULT 0,"
            . " PRIMARY KEY (`code_hash`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t('mcp_oauth_token') . " ("
            . " `token_id` INT(11) NOT NULL AUTO_INCREMENT,"
            . " `access_hash` CHAR(64) NOT NULL,"
            . " `refresh_hash` CHAR(64) NOT NULL,"
            . " `client_id` VARCHAR(255) NOT NULL,"
            . " `client_name` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `user_id` INT(11) NOT NULL DEFAULT 0,"
            . " `username` VARCHAR(64) NOT NULL DEFAULT '',"
            . " `scope` VARCHAR(255) NOT NULL DEFAULT '',"
            . " `access_expires` DATETIME NOT NULL,"
            . " `refresh_expires` DATETIME NOT NULL,"
            . " `revoked` TINYINT(1) NOT NULL DEFAULT 0,"
            . " `ip` VARCHAR(45) NOT NULL DEFAULT '',"
            . " `date_added` DATETIME NOT NULL,"
            . " `last_used` DATETIME NOT NULL,"
            . " PRIMARY KEY (`token_id`),"
            . " UNIQUE KEY `access_hash` (`access_hash`),"
            . " KEY `refresh_hash` (`refresh_hash`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(Logger::ARCHIVE) . " LIKE " . $this->db->t(Logger::TABLE));

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(Logger::SETS) . " ("
            . " `archive_id` INT(11) NOT NULL AUTO_INCREMENT,"
            . " `first_id` INT(11) NOT NULL DEFAULT 0,"
            . " `last_id` INT(11) NOT NULL DEFAULT 0,"
            . " `rows` INT(11) NOT NULL DEFAULT 0,"
            . " `date_from` DATE NOT NULL,"
            . " `date_to` DATE NOT NULL,"
            . " `created_by` VARCHAR(64) NOT NULL DEFAULT '',"
            . " `date_added` DATETIME NOT NULL,"
            . " PRIMARY KEY (`archive_id`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(Logger::ANCHOR) . " ("
            . " `anchor_id` INT(11) NOT NULL,"
            . " `last_id` INT(11) NOT NULL DEFAULT 0,"
            . " `last_hash` CHAR(64) NOT NULL DEFAULT '',"
            . " `date_modified` DATETIME NOT NULL,"
            . " PRIMARY KEY (`anchor_id`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(ChangeJournal::TABLE) . " ("
            . " `change_id` INT(11) NOT NULL AUTO_INCREMENT,"
            . " `batch_id` VARCHAR(32) NOT NULL,"
            . " `date_added` DATETIME NOT NULL,"
            . " `client` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `caller` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `tool` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `table_name` VARCHAR(64) NOT NULL,"
            . " `action` VARCHAR(10) NOT NULL,"
            . " `pk` TEXT NOT NULL,"
            . " `before_data` MEDIUMTEXT NULL,"
            . " `after_data` MEDIUMTEXT NULL,"
            . " `high_risk` TINYINT(1) NOT NULL DEFAULT 0,"
            . " `status` VARCHAR(16) NOT NULL DEFAULT 'applied',"
            . " `execute_after` DATETIME NULL,"
            . " `note` VARCHAR(255) NOT NULL DEFAULT '',"
            . " `done_by` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `date_done` DATETIME NULL,"
            . " `batch_no` INT(11) NOT NULL DEFAULT 0,"
            . " PRIMARY KEY (`change_id`), KEY `batch_id` (`batch_id`), KEY `status` (`status`, `execute_after`), KEY `caller` (`caller`, `date_added`), KEY `batch_no` (`batch_no`), KEY `date_added` (`date_added`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // one short number per change (tool call), the one the AI names and the owner rolls back by
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(ChangeJournal::BATCHES) . " ("
            . " `batch_no` INT(11) NOT NULL AUTO_INCREMENT,"
            . " `batch_id` VARCHAR(32) NOT NULL,"
            . " `date_added` DATETIME NOT NULL,"
            . " PRIMARY KEY (`batch_no`), UNIQUE KEY `batch_id` (`batch_id`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(Mailer::TABLE) . " ("
            . " `mail_id` INT(11) NOT NULL AUTO_INCREMENT,"
            . " `date_added` DATETIME NOT NULL,"
            . " `client` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `topic` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `subject` VARCHAR(255) NOT NULL DEFAULT '',"
            . " `html` MEDIUMTEXT NOT NULL,"
            . " `text` MEDIUMTEXT NOT NULL,"
            . " `recipients` MEDIUMTEXT NOT NULL,"
            . " `audience` TEXT NOT NULL,"
            . " `broadcast` TINYINT(1) NOT NULL DEFAULT 0,"
            . " `status` VARCHAR(16) NOT NULL DEFAULT 'draft',"
            . " `total` INT(11) NOT NULL DEFAULT 0,"
            . " `sent` INT(11) NOT NULL DEFAULT 0,"
            . " `failed` INT(11) NOT NULL DEFAULT 0,"
            . " `expires` DATETIME NOT NULL,"
            . " `last_error` VARCHAR(255) NOT NULL DEFAULT '',"
            . " PRIMARY KEY (`mail_id`), KEY `status` (`status`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(ImageStore::TABLE) . " ("
            . " `image_id` INT(11) NOT NULL AUTO_INCREMENT,"
            . " `path` VARCHAR(255) NOT NULL,"
            . " `sha1` CHAR(40) NOT NULL,"
            . " `source` VARCHAR(255) NOT NULL DEFAULT '',"
            . " `batch_id` VARCHAR(32) NOT NULL DEFAULT '',"
            . " `upload_id` INT(11) NOT NULL DEFAULT 0,"
            . " `width` INT(11) NOT NULL DEFAULT 0,"
            . " `height` INT(11) NOT NULL DEFAULT 0,"
            . " `bytes` INT(11) NOT NULL DEFAULT 0,"
            . " `date_added` DATETIME NOT NULL,"
            . " `moved_from` VARCHAR(255) NOT NULL DEFAULT '',"
            . " PRIMARY KEY (`image_id`), KEY `sha1` (`sha1`), KEY `batch_id` (`batch_id`), KEY `upload_id` (`upload_id`), KEY `moved_from` (`moved_from`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(Series::TABLE) . " ("
            . " `code` CHAR(18) NOT NULL,"
            . " `tool` VARCHAR(64) NOT NULL,"
            . " `caller` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `kind` VARCHAR(255) NOT NULL DEFAULT '',"
            . " `remaining` INT(11) NOT NULL DEFAULT 0,"
            . " `expires` DATETIME NOT NULL,"
            . " `date_added` DATETIME NOT NULL,"
            . " PRIMARY KEY (`code`), KEY `expires` (`expires`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(ImageStore::TRASH) . " ("
            . " `trash_id` INT(11) NOT NULL AUTO_INCREMENT,"
            . " `path` VARCHAR(255) NOT NULL,"
            . " `trash_path` VARCHAR(255) NOT NULL,"
            . " `batch_id` VARCHAR(32) NOT NULL DEFAULT '',"
            . " `date_added` DATETIME NOT NULL,"
            . " PRIMARY KEY (`trash_id`), KEY `batch_id` (`batch_id`), KEY `date_added` (`date_added`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(ImageStore::UPLOAD) . " ("
            . " `upload_id` INT(11) NOT NULL AUTO_INCREMENT,"
            . " `token_hash` CHAR(64) NOT NULL,"
            . " `batch_id` VARCHAR(32) NOT NULL DEFAULT '',"
            . " `client` VARCHAR(128) NOT NULL DEFAULT '',"
            . " `purpose` VARCHAR(32) NOT NULL DEFAULT 'product',"
            . " `name` VARCHAR(255) NOT NULL DEFAULT '',"
            . " `dir` VARCHAR(255) NOT NULL DEFAULT '',"
            . " `max_files` INT(11) NOT NULL DEFAULT 10,"
            . " `used` INT(11) NOT NULL DEFAULT 0,"
            . " `expires` DATETIME NOT NULL,"
            . " `date_added` DATETIME NOT NULL,"
            . " PRIMARY KEY (`upload_id`), UNIQUE KEY `token_hash` (`token_hash`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(Idempotency::TABLE) . " ("
            . " `key_hash` CHAR(64) NOT NULL,"
            . " `tool` VARCHAR(64) NOT NULL DEFAULT '',"
            . " `status` VARCHAR(16) NOT NULL DEFAULT 'running',"
            . " `result` MEDIUMTEXT NOT NULL,"
            . " `date_added` DATETIME NOT NULL,"
            . " `expires` DATETIME NOT NULL,"
            . " PRIMARY KEY (`key_hash`), KEY `expires` (`expires`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(PromptLibrary::TABLE) . " ("
            . " `name` VARCHAR(64) NOT NULL,"
            . " `title` VARCHAR(255) NOT NULL DEFAULT '',"
            . " `description` VARCHAR(500) NOT NULL DEFAULT '',"
            . " `body` MEDIUMTEXT NOT NULL,"
            . " `section` VARCHAR(64) NOT NULL DEFAULT '',"
            . " `enabled` TINYINT(1) NOT NULL DEFAULT 1,"
            . " `custom` TINYINT(1) NOT NULL DEFAULT 0,"
            . " `deleted` TINYINT(1) NOT NULL DEFAULT 0,"
            . " `sort_order` INT(11) NOT NULL DEFAULT 0,"
            . " `date_modified` DATETIME NOT NULL,"
            . " PRIMARY KEY (`name`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(PromptLibrary::SECTIONS) . " ("
            . " `section_key` VARCHAR(64) NOT NULL,"
            . " `title` VARCHAR(100) NOT NULL DEFAULT '',"
            . " `deleted` TINYINT(1) NOT NULL DEFAULT 0,"
            . " `sort_order` INT(11) NOT NULL DEFAULT 0,"
            . " PRIMARY KEY (`section_key`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(RateLimiter::TABLE) . " ("
            . " `bucket` VARCHAR(128) NOT NULL,"
            . " `window_start` INT(11) NOT NULL DEFAULT 0,"
            . " `hits` INT(11) NOT NULL DEFAULT 0,"
            . " PRIMARY KEY (`bucket`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $this->maintenanceStartup(true);
    }

    const STARTUP_CODE = 'oc_mcp_maintenance';

    /**
     * OpenCart 4: a startup action of the module runs before the maintenance check and lets the MCP and OAuth routes
     * through while the shop is closed for visitors (otherwise the AI gets the "closed" page and ChatGPT says "the tool
     * failed internally"). OpenCart 3 does the same with the module's OCMOD (install.xml).
     */
    public function maintenanceStartup(bool $on): void {
        if (!$this->db->tableExists('startup')) {
            return;
        }

        try {
            $this->db->exec("DELETE FROM " . $this->db->t('startup') . " WHERE `code` = " . $this->db->q(self::STARTUP_CODE));

            if ($on) {
                // OpenCart 4.0.x has no description column (4.1 added it)
                $desc = $this->db->rows("SHOW COLUMNS FROM " . $this->db->t('startup') . " LIKE 'description'") ? ", `description` = 'MCP Server: AI connection works while the store is in maintenance'" : '';
                $this->db->exec("INSERT INTO " . $this->db->t('startup') . " SET `code` = " . $this->db->q(self::STARTUP_CODE) . $desc
                    . ", `action` = 'catalog/extension/ai_admin_oc4/startup/mcp_server', `status` = 1, `sort_order` = 0");
            }
        } catch (\Throwable $e) {
            // never stops an install or update: only the AI connection in maintenance mode is missing
        }
    }

    /** Setting row (code = key) with the schema this store was last upgraded to: the settings page runs the upgrade only when it differs. */
    const SCHEMA_KEY = 'mcp_server_schema';

    /** The schema of this version: a hash of this file, so any change to the upgrade steps below runs them once more. */
    public static function schemaId(): string {
        return (string)md5_file(__FILE__);
    }

    /** Idempotent schema upgrades for installs made by older versions; a no-op (one SELECT) when the store is already on this schema. */
    public function upgrade(): void {
        if ($this->storedSchema() === self::schemaId()) {
            return;
        }

        // two admin pages opened at once must not both run the ALTERs below (the second would fail on "duplicate column")
        $lock = $this->db->q($this->db->prefix() . 'mcp_upgrade');
        $locked = (int)$this->db->value("SELECT GET_LOCK(" . $lock . ", 30) AS l", 'l') === 1;

        try {
            if ($this->storedSchema() !== self::schemaId()) { // the other page may have finished while this one waited
                $this->runUpgrade();
                $this->setStoredSchema(self::schemaId());
            }
        } finally {
            if ($locked) {
                $this->db->value("SELECT RELEASE_LOCK(" . $lock . ") AS l", 'l');
            }
        }
    }

    private function runUpgrade(): void {
        $this->install();

        $cols = [];

        foreach ($this->db->rows("SHOW COLUMNS FROM " . $this->db->t(Logger::TABLE)) as $c) {
            $cols[$c['Field']] = true;
        }

        if (!isset($cols['prev_hash'])) {
            $this->db->exec("ALTER TABLE " . $this->db->t(Logger::TABLE) . " ADD `prev_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `duration_ms`");
        }

        if (!isset($cols['caller'])) {
            // which connection made the call (OAuth app + user, or token + IP): the owner's name for it is shown in the log; not part of the hash chain
            $this->db->exec("ALTER TABLE " . $this->db->t(Logger::TABLE) . " ADD `caller` VARCHAR(128) NOT NULL DEFAULT '' AFTER `client`");
        }

        if (!isset($cols['hash'])) {
            $this->db->exec("ALTER TABLE " . $this->db->t(Logger::TABLE) . " ADD `hash` CHAR(64) NOT NULL DEFAULT '' AFTER `prev_hash`");
        }

        $archiveCols = $this->db->rows("SHOW COLUMNS FROM " . $this->db->t(Logger::ARCHIVE) . " LIKE 'hash'");

        if (!$archiveCols) {
            $this->db->exec("DROP TABLE IF EXISTS " . $this->db->t(Logger::ARCHIVE)); // created by an older install() before the hash columns existed; empty by construction
            $this->db->exec("CREATE TABLE " . $this->db->t(Logger::ARCHIVE) . " LIKE " . $this->db->t(Logger::TABLE));
        }

        if (!$this->db->rows("SHOW COLUMNS FROM " . $this->db->t(Logger::ARCHIVE) . " LIKE 'caller'")) {
            $this->db->exec("ALTER TABLE " . $this->db->t(Logger::ARCHIVE) . " ADD `caller` VARCHAR(128) NOT NULL DEFAULT '' AFTER `client`");
        }

        if (!$this->db->rows("SHOW COLUMNS FROM " . $this->db->t(ChangeJournal::TABLE) . " LIKE 'caller'")) {
            // which connection made a change (OAuth app + user, or token + IP): "undo my changes" filters on it
            $this->db->exec("ALTER TABLE " . $this->db->t(ChangeJournal::TABLE) . " ADD `caller` VARCHAR(128) NOT NULL DEFAULT '' AFTER `client`, ADD KEY `caller` (`caller`, `date_added`)");
        }

        if (!$this->db->rows("SHOW COLUMNS FROM " . $this->db->t(ChangeJournal::TABLE) . " LIKE 'done_by'")) {
            // who rolled a change back (admin:user, the AI's connection) or cancelled a deletion, and when: shown in History
            $this->db->exec("ALTER TABLE " . $this->db->t(ChangeJournal::TABLE) . " ADD `done_by` VARCHAR(128) NOT NULL DEFAULT '' AFTER `note`, ADD `date_done` DATETIME NULL AFTER `done_by`");
        }

        // the dashboard's last-7-days counts, the hourly clean-up of the journal and of the photo trash filter by date
        foreach ([ChangeJournal::TABLE, ImageStore::TRASH] as $t) {
            if ($this->db->tableExists($t) && !$this->db->rows("SHOW INDEX FROM " . $this->db->t($t) . " WHERE Key_name = 'date_added'")) {
                $this->db->exec("ALTER TABLE " . $this->db->t($t) . " ADD KEY `date_added` (`date_added`)");
            }
        }

        if (!$this->db->rows("SHOW COLUMNS FROM " . $this->db->t(ChangeJournal::TABLE) . " LIKE 'batch_no'")) {
            // a short number per change: the AI names it, the owner finds and rolls the change back by it; old changes get theirs in date order
            $this->db->exec("ALTER TABLE " . $this->db->t(ChangeJournal::TABLE) . " ADD `batch_no` INT(11) NOT NULL DEFAULT 0 AFTER `date_done`, ADD KEY `batch_no` (`batch_no`)");
        }

        $this->db->exec("CREATE TABLE IF NOT EXISTS " . $this->db->t(ChangeJournal::BATCHES) . " ("
            . " `batch_no` INT(11) NOT NULL AUTO_INCREMENT,"
            . " `batch_id` VARCHAR(32) NOT NULL,"
            . " `date_added` DATETIME NOT NULL,"
            . " PRIMARY KEY (`batch_no`), UNIQUE KEY `batch_id` (`batch_id`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $this->db->exec("INSERT IGNORE INTO " . $this->db->t(ChangeJournal::BATCHES) . " (`batch_id`, `date_added`) SELECT `batch_id`, MIN(`date_added`) FROM " . $this->db->t(ChangeJournal::TABLE) . " WHERE `batch_no` = 0 GROUP BY `batch_id` ORDER BY MIN(`change_id`)");
        $this->db->exec("UPDATE " . $this->db->t(ChangeJournal::TABLE) . " c INNER JOIN " . $this->db->t(ChangeJournal::BATCHES) . " b ON b.`batch_id` = c.`batch_id` SET c.`batch_no` = b.`batch_no` WHERE c.`batch_no` = 0");
        // a bulk INSERT ... SELECT makes InnoDB reserve numbers in powers of two: the next change would get #4097 after #2164, so the counter is put right behind the last number
        $this->db->exec("ALTER TABLE " . $this->db->t(ChangeJournal::BATCHES) . " AUTO_INCREMENT = " . ((int)$this->db->value("SELECT MAX(`batch_no`) AS m FROM " . $this->db->t(ChangeJournal::BATCHES), 'm') + 1));

        foreach (['section' => "VARCHAR(64) NOT NULL DEFAULT '' AFTER `body`", 'deleted' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `custom`"] as $col => $def) {
            if (!$this->db->rows("SHOW COLUMNS FROM " . $this->db->t(PromptLibrary::TABLE) . " LIKE '" . $col . "'")) {
                $this->db->exec("ALTER TABLE " . $this->db->t(PromptLibrary::TABLE) . " ADD `" . $col . "` " . $def);
            }
        }

        // the first version shipped 21 prompts; rows of the removed ones mean nothing now (the shipped list is the one source).
        // FREE build ships no prompts (defaults() is empty): drop all shipped rows, and never build an empty NOT IN () (a SQL error).
        $shippedKeys = array_keys(PromptLibrary::defaults());

        if ($shippedKeys) {
            $shipped = implode(', ', array_map([$this->db, 'q'], $shippedKeys));
            $this->db->exec("DELETE FROM " . $this->db->t(PromptLibrary::TABLE) . " WHERE `custom` = 0 AND `name` NOT IN (" . $shipped . ")");
        } else {
            $this->db->exec("DELETE FROM " . $this->db->t(PromptLibrary::TABLE) . " WHERE `custom` = 0");
        }

        // a photo imported for several pages moves out of catalog/_incoming with the first one: the others find it by its old path
        if ($this->db->tableExists(ImageStore::TABLE) && !$this->db->rows("SHOW COLUMNS FROM " . $this->db->t(ImageStore::TABLE) . " LIKE 'moved_from'")) {
            $this->db->exec("ALTER TABLE " . $this->db->t(ImageStore::TABLE) . " ADD `moved_from` VARCHAR(255) NOT NULL DEFAULT '', ADD KEY `moved_from` (`moved_from`)");
        }

        if (!$this->db->rows("SHOW COLUMNS FROM " . $this->db->t(ImageStore::UPLOAD) . " LIKE 'dir'")) {
            $this->db->exec("ALTER TABLE " . $this->db->t(ImageStore::UPLOAD) . " ADD `dir` VARCHAR(255) NOT NULL DEFAULT '' AFTER `name`");
        }

        if (defined('DIR_IMAGE') && $this->db->tableExists(ImageStore::TRASH)) {
            try {
                ImageStore::moveOldTrash($this->db, (string)constant('DIR_IMAGE')); // the trash leaves the image manager's folder
            } catch (\Throwable $e) {
            }
        }
    }

    private function storedSchema(): string {
        try {
            return (string)$this->db->value("SELECT `value` FROM " . $this->db->t('setting') . " WHERE `store_id` = 0 AND `code` = " . $this->db->q(self::SCHEMA_KEY) . " LIMIT 1", 'value');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** '' forgets the schema: the next settings page runs the whole upgrade. */
    private function setStoredSchema(string $id): void {
        $this->db->exec("DELETE FROM " . $this->db->t('setting') . " WHERE `store_id` = 0 AND `code` = " . $this->db->q(self::SCHEMA_KEY));

        if ($id !== '') {
            $this->db->exec("INSERT INTO " . $this->db->t('setting') . " SET `store_id` = 0, `code` = " . $this->db->q(self::SCHEMA_KEY) . ", `key` = " . $this->db->q(self::SCHEMA_KEY) . ", `value` = " . $this->db->q($id) . ", `serialized` = 0");
        }
    }

    /**
     * Indexes the AI tools rely on that stock OpenCart 3 does not have (orders by customer / status / date, order
     * history and options by order, customers by e-mail). Named mcp_*, added only when no index already starts with
     * that column. Built only by the Install button (never by opening the settings page), kept across updates
     * (uninstall + install), removed by "Remove the module completely". Tables above MAX_INDEX_ROWS are skipped:
     * building an index locks a MyISAM table, and stock OpenCart 3 keeps orders in MyISAM, so checkout would wait.
     */
    const STORE_INDEXES = [
        ['order', 'customer_id'], ['order', 'order_status_id'], ['order', 'date_added'],
        ['order_history', 'order_id'], ['order_option', 'order_id'], ['customer', 'email'],
    ];
    const MAX_INDEX_ROWS = 300000; // a few seconds of ALTER on shared hosting

    /** @return array{added: string[], skipped: string[]} */
    public function ensureStoreIndexes(): array {
        $out = ['added' => [], 'skipped' => []];
        $have = [];

        foreach ($this->db->rows("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND SEQ_IN_INDEX = 1 AND TABLE_NAME LIKE " . $this->db->q(str_replace('_', '\\_', $this->db->prefix()) . '%')) as $r) {
            $have[strtolower($r['TABLE_NAME'] . '.' . $r['COLUMN_NAME'])] = true;
        }

        foreach (self::STORE_INDEXES as [$table, $column]) {
            $full = $this->db->prefix() . $table;

            if (isset($have[strtolower($full . '.' . $column)]) || !$this->db->tableExists($table)) {
                continue;
            }

            $rows = (int)$this->db->value("SELECT TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $this->db->q($full), 'n');

            if ($rows > self::MAX_INDEX_ROWS) {
                $out['skipped'][] = $full . '.' . $column;
                continue;
            }

            try {
                $this->db->exec("ALTER TABLE " . $this->db->t($table) . " ADD INDEX `mcp_" . $column . "` (`" . $column . "`)");
                $out['added'][] = $full . '.' . $column;
            } catch (\Throwable $e) {
                $out['skipped'][] = $full . '.' . $column;
            }
        }

        return $out;
    }

    public function dropStoreIndexes(): void {
        foreach (self::STORE_INDEXES as [$table, $column]) {
            try {
                if ($this->db->tableExists($table) && $this->db->rows("SHOW INDEX FROM " . $this->db->t($table) . " WHERE Key_name = " . $this->db->q('mcp_' . $column))) {
                    $this->db->exec("ALTER TABLE " . $this->db->t($table) . " DROP INDEX `mcp_" . $column . "`");
                }
            } catch (\Throwable $e) {
            }
        }
    }

    /** The module's own tables (everything it keeps: logs, journal and rollbacks, connections, mails, prompts, media records). */
    const TABLES = [Logger::TABLE, Logger::ARCHIVE, Logger::ANCHOR, Logger::SETS, ChangeJournal::TABLE, ChangeJournal::BATCHES, 'mcp_mail', 'mcp_idem', 'mcp_oauth_client', 'mcp_oauth_code', 'mcp_oauth_token',
                    RateLimiter::TABLE, ImageStore::TABLE, ImageStore::UPLOAD, ImageStore::TRASH, PromptLibrary::TABLE, PromptLibrary::SECTIONS, Series::TABLE];

    /**
     * Uninstall from Extensions > Modules: the module's tables, settings and the indexes on the store's tables stay,
     * so updating the module (uninstall the old one, install the new one) keeps the journal, connections, prompts and access
     * and rebuilds nothing on the live order tables.
     * Everything goes with wipe(), the owner's button "Remove the module completely" / "Clear the module data".
     */
    public function uninstall(): void {
        $this->maintenanceStartup(false);
        $this->setStoredSchema(''); // the install that follows (an update) re-checks the whole schema
    }

    /** Rows per module table right now (what a wipe deletes). */
    public function rowCounts(): array {
        $out = [];

        foreach (self::TABLES as $table) {
            if ($this->db->tableExists($table)) {
                $out[$table] = (int)$this->db->value("SELECT COUNT(*) AS n FROM " . $this->db->t($table), 'n');
            }
        }

        return $out;
    }

    /**
     * Drop every module table and empty the photo trash. Imported photos the pages use stay in image/catalog.
     * @return array{tables:int, rows:int, trash:array{files:int, bytes:int}}
     */
    public function wipe(string $imageDir): array {
        $counts = $this->rowCounts();
        $trash = ImageStore::emptyTrash($imageDir);
        $this->dropStoreIndexes();

        foreach (self::TABLES as $table) {
            $this->db->exec("DROP TABLE IF EXISTS " . $this->db->t($table));
        }

        $this->setStoredSchema('');

        return ['tables' => count($counts), 'rows' => array_sum($counts), 'trash' => $trash];
    }

    /** Generate a URL-safe random secret. */
    public static function randomToken(int $bytes = 24): string {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
