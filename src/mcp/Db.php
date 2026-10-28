<?php
namespace OcMcp;

/**
 * Thin wrapper over OpenCart's DB object (identical API in OC 3.x and 4.x: query/escape/getLastId/countAffected).
 */
final class Db {
    /** @var object OpenCart DB instance */
    private $oc;
    /** @var string */
    private $prefix;

    public function __construct($ocDb, string $prefix) {
        $this->oc = $ocDb;
        $this->prefix = $prefix;
    }

    public function prefix(): string {
        return $this->prefix;
    }

    /** Back-ticked, prefixed table name. */
    public function t(string $table): string {
        return '`' . $this->prefix . $table . '`';
    }

    public function rows(string $sql): array {
        $r = $this->oc->query($sql);

        return (is_object($r) && isset($r->rows) && is_array($r->rows)) ? $r->rows : [];
    }

    public function row(string $sql): ?array {
        $r = $this->oc->query($sql);

        return (is_object($r) && !empty($r->row)) ? $r->row : null;
    }

    public function value(string $sql, string $column) {
        $row = $this->row($sql);

        return $row !== null && array_key_exists($column, $row) ? $row[$column] : null;
    }

    /** Run a write statement; returns affected rows. */
    public function exec(string $sql): int {
        $this->oc->query($sql);

        return (int)$this->oc->countAffected();
    }

    public function lastId(): int {
        return (int)$this->oc->getLastId();
    }

    public function esc($value): string {
        return $this->oc->escape((string)$value);
    }

    /** Quoted + escaped string literal. */
    public function q($value): string {
        return "'" . $this->esc($value) . "'";
    }

    /** Comma separated list of ints for IN (...). Never empty. */
    public function ints(array $values): string {
        $list = implode(',', array_map('intval', $values));

        return $list !== '' ? $list : '0';
    }

    /** LIKE pattern with wildcards escaped. */
    /** Whether a row with this id exists. */
    public function exists(string $table, string $column, int $id): bool {
        return $this->row("SELECT 1 AS x FROM " . $this->t($table) . " WHERE `" . $column . "` = " . $id . " LIMIT 1") !== null;
    }

    /** The ids of $ids that have no row in $table (looked up 1000 at a time), in the order given. */
    public function missingIds(string $table, string $column, array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $known = [];

        foreach (array_chunk($ids, 1000) as $part) {
            foreach ($this->rows("SELECT `" . $column . "` AS id FROM " . $this->t($table) . " WHERE `" . $column . "` IN (" . $this->ints($part) . ")") as $r) {
                $known[(int)$r['id']] = true;
            }
        }

        return array_values(array_filter($ids, function ($id) use ($known) { return !isset($known[$id]); }));
    }

    public function like($value): string {
        $v = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$value);

        return "'%" . $this->esc($v) . "%'";
    }

    /** @var int nesting depth of begin() calls */
    private $txDepth = 0;

    public function begin(): void {
        if ($this->txDepth++ === 0) {
            $this->oc->query("START TRANSACTION");
        }
    }

    public function commit(): void {
        if ($this->txDepth > 0 && --$this->txDepth === 0) {
            $this->oc->query("COMMIT");
        }
    }

    public function rollback(): void {
        if ($this->txDepth > 0) {
            $this->txDepth = 0;
            $this->oc->query("ROLLBACK");
        }
    }

    /**
     * Run $fn with a per-statement execution time limit (MySQL 5.7+ MAX_EXECUTION_TIME for SELECT,
     * MariaDB 10.1+ max_statement_time). Silently runs without a limit on servers that support neither.
     */
    /** @var string|null cached VERSION() */
    private $serverVersion = null;

    public function withTimeout(int $ms, callable $fn) {
        $reset = null;

        try {
            if ($this->serverVersion === null) {
                $this->serverVersion = strtolower((string)$this->value("SELECT VERSION() AS v", 'v'));
            }

            $version = $this->serverVersion;

            if (strpos($version, 'mariadb') !== false) {
                $this->oc->query("SET SESSION max_statement_time = " . round($ms / 1000, 3));
                $reset = "SET SESSION max_statement_time = 0";
            } else {
                $this->oc->query("SET SESSION MAX_EXECUTION_TIME = " . (int)$ms);
                $reset = "SET SESSION MAX_EXECUTION_TIME = 0";
            }
        } catch (\Throwable $e) {
            $reset = null;
        }

        try {
            return $fn();
        } finally {
            if ($reset !== null) {
                try {
                    $this->oc->query($reset);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    public function tableExists(string $table): bool {
        $rows = $this->rows("SHOW TABLES LIKE '" . $this->esc($this->prefix . $table) . "'");

        return !empty($rows);
    }
}
