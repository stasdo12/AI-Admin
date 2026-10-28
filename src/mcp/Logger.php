<?php
namespace OcMcp;

/**
 * Append-only, hash-chained audit log in `{prefix}mcp_log`.
 *
 * Every row stores prev_hash and hash = sha256(prev_hash + row fields). Editing or deleting a row in the middle
 * breaks the chain, which verify() detects. Rows are never updated or deleted by the module.
 */
final class Logger {
    const TABLE = 'mcp_log';
    const ARCHIVE = 'mcp_log_archive';
    const ANCHOR = 'mcp_log_anchor';
    /** One row per archive the History tab lists: the record ids and dates it holds, who made it and when. */
    const SETS = 'mcp_log_archive_set';

    /** @var Db */
    private $db;
    /** @var bool */
    private $enabled;
    /** @var bool|null whether the hash columns exist (null = not checked yet) */
    private static $chained = null;
    /** @var bool|null whether the caller column exists */
    private static $hasCaller = null;

    public function __construct(Db $db, bool $enabled = true) {
        $this->db = $db;
        $this->enabled = $enabled;
    }

    /** Longest arguments a log row keeps (bytes). */
    const MAX_ARGS = 20000;

    /**
     * Arguments as JSON that fits the row and stays valid JSON: long texts are shortened inside their strings
     * ("...[+5123 chars]"), then long lists, so the flags, ids, approval and keys of a call are always readable.
     * Cutting the encoded text left 60 rows of the October logs unreadable.
     */
    public static function fit($arguments, int $max = self::MAX_ARGS): string {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);

            if (!is_array($decoded)) {
                return strlen($arguments) > $max ? mb_strcut($arguments, 0, $max, 'UTF-8') . '...' : $arguments;
            }

            $arguments = $decoded;
        }

        $json = (string)json_encode($arguments, $flags);

        foreach ([[2000, 200], [500, 200], [160, 60], [60, 20], [20, 5]] as [$text, $items]) {
            if (strlen($json) <= $max) {
                return $json;
            }

            $json = (string)json_encode(self::shrink($arguments, $text, $items), $flags);
        }

        return strlen($json) <= $max ? $json : (string)json_encode(['_truncated' => true, '_bytes' => strlen($json), '_keys' => is_array($arguments) ? array_slice(array_keys($arguments), 0, 50) : []], $flags);
    }

    private static function shrink($v, int $text, int $items) {
        if (is_string($v)) {
            $n = mb_strlen($v, 'UTF-8');

            return $n > $text ? mb_substr($v, 0, $text, 'UTF-8') . '...[+' . ($n - $text) . ' chars]' : $v;
        }

        if (!is_array($v)) {
            return $v;
        }

        $list = array_keys($v) === range(0, count($v) - 1);
        $out = [];
        $i = 0;

        foreach ($v as $k => $x) {
            if ($list && $i === $items) {
                $out[] = '...[+' . (count($v) - $items) . ' items]';
                break;
            }

            $out[$k] = self::shrink($x, $text, $items);
            $i++;
        }

        return $out;
    }

    /** Mask personal data before it reaches the log: e-mails (a***@domain.com) and phone numbers (********12). */
    public static function mask(string $s): string {
        $s = preg_replace_callback('/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', function ($m) {
            return $m[1] . '***' . $m[2];
        }, $s);

        // links stay whole (an image URL with a long number in its path is not a phone), the rest is checked
        $parts = preg_split('~(https?://[^\s"\'<>]+)~i', $s, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }

            // a number glued to a word by - / _ (an idempotency key "orders-2-to-5-2026-09-20", a file name) is not a phone either
            $parts[$i] = preg_replace_callback('~(?<![\w./-])\+?\d[\d\s().-]{8,}\d(?![\w./-])~', function ($m) {
                $digits = preg_replace('/\D/', '', $m[0]);

                if (strlen($digits) < 10 || strlen($digits) > 15 || preg_match('/^\d{4}-\d{2}-\d{2}/', $m[0])) {
                    return $m[0];
                }

                return str_repeat('*', strlen($digits) - 2) . substr($digits, -2);
            }, $part);
        }

        return implode('', $parts);
    }

    public static function chain(string $prev, array $fields): string {
        return hash('sha256', $prev . "\n" . implode("\x1f", array_map('strval', $fields)));
    }

    public function log(string $ip, string $client, string $tool, $arguments, string $status, string $message = '', int $durationMs = 0, string $caller = ''): void {
        if (!$this->enabled) {
            return;
        }

        $lock = $this->db->q($this->db->prefix() . 'mcp_log');
        $locked = false;

        try {
            $args = self::fit($arguments, self::MAX_ARGS);

            if (strlen($message) > 4000) {
                $message = substr($message, 0, 4000) . '...';
            }

            $args = self::mask($args);
            $message = self::mask($message);

            $fields = [
                date('Y-m-d H:i:s'),
                substr($ip, 0, 45),
                substr($client, 0, 128),
                substr($tool, 0, 128),
                $args,
                substr($status, 0, 16),
                $message,
                (string)(int)$durationMs,
            ];

            $set = " `date_added` = " . $this->db->q($fields[0]) . ", `ip` = " . $this->db->q($fields[1]) . ", `client` = " . $this->db->q($fields[2])
                . ", `tool` = " . $this->db->q($fields[3]) . ", `arguments` = " . $this->db->q($fields[4]) . ", `status` = " . $this->db->q($fields[5])
                . ", `message` = " . $this->db->q($fields[6]) . ", `duration_ms` = " . (int)$fields[7]
                . ($caller !== '' && $this->hasCaller() ? ", `caller` = " . $this->db->q(substr($caller, 0, 128)) : ''); // who: outside the hash, the owner's name for the connection is looked up by it

            if ($this->chained()) {
                // never chain without mutual exclusion: two writers reading the same previous hash would fork the chain
                // and verify() would report tampering forever. Wait up to 3 x 5 s, then give up on this one record.
                for ($try = 0; $try < 3 && !$locked; $try++) {
                    $locked = (int)$this->db->value("SELECT GET_LOCK(" . $lock . ", 5) AS l", 'l') === 1;
                }

                if (!$locked) {
                    throw new \RuntimeException('log lock busy');
                }

                $prev = (string)$this->db->value("SELECT `hash` FROM " . $this->db->t(self::TABLE) . " ORDER BY `mcp_log_id` DESC LIMIT 1", 'hash');

                if ($prev === '' && $this->db->tableExists(self::ARCHIVE)) {
                    $prev = (string)$this->db->value("SELECT `hash` FROM " . $this->db->t(self::ARCHIVE) . " ORDER BY `mcp_log_id` DESC LIMIT 1", 'hash'); // a cleared log continues the archived chain
                }

                $set .= ", `prev_hash` = " . $this->db->q($prev) . ", `hash` = " . $this->db->q(self::chain($prev, $fields));
            }

            $this->db->exec("INSERT INTO " . $this->db->t(self::TABLE) . " SET" . $set);

            if ($this->chained()) {
                $id = $this->db->lastId();
                $this->db->exec("REPLACE INTO " . $this->db->t(self::ANCHOR) . " SET `anchor_id` = 1, `last_id` = " . (int)$id
                    . ", `last_hash` = " . $this->db->q(self::chain($prev, $fields)) . ", `date_modified` = NOW()");
            }
        } catch (\Throwable $e) {
            // never let logging break the request
        } finally {
            if ($locked) {
                try {
                    $this->db->value("SELECT RELEASE_LOCK(" . $lock . ") AS l", 'l');
                } catch (\Throwable $e) {
                }
            }
        }

        // housekeeping rides on the writes themselves: the live log stays near LIVE_ROWS however the rows arrive (tool calls or refused requests)
        if (mt_rand(1, 200) === 1) {
            try {
                $this->autoArchive();
            } catch (\Throwable $e) {
                // an older install without the archive table: the owner archives by hand
            }
        }
    }

    private function hasCaller(): bool {
        if (self::$hasCaller === null) {
            try {
                self::$hasCaller = (bool)$this->db->rows("SHOW COLUMNS FROM " . $this->db->t(self::TABLE) . " LIKE 'caller'");
            } catch (\Throwable $e) {
                self::$hasCaller = false;
            }
        }

        return self::$hasCaller;
    }

    private function chained(): bool {
        if (self::$chained === null) {
            try {
                self::$chained = (bool)$this->db->rows("SHOW COLUMNS FROM " . $this->db->t(self::TABLE) . " LIKE 'hash'");
            } catch (\Throwable $e) {
                self::$chained = false;
            }
        }

        return self::$chained;
    }

    /**
     * Walk the archive and the live log (in id order) and recompute the chain; compare the tail with the anchor.
     * @return array{ok:bool,checked:int,legacy:int,first_bad_id:?int,reason:string,last_hash:string,last_id:int}
     */
    public function verify(int $batch = 1000): array {
        $out = ['ok' => true, 'checked' => 0, 'legacy' => 0, 'first_bad_id' => null, 'reason' => '', 'last_hash' => '', 'last_id' => 0];
        $prev = '';
        $chainStarted = false;
        $lastId = 0;
        $tables = [];

        if ($this->db->tableExists(self::ARCHIVE)) {
            $tables[] = self::ARCHIVE;
        }

        $tables[] = self::TABLE;

        foreach ($tables as $table) {
            do {
                $rows = $this->db->rows("SELECT * FROM " . $this->db->t($table) . " WHERE `mcp_log_id` > " . (int)$lastId . " ORDER BY `mcp_log_id` ASC LIMIT " . (int)$batch);

                foreach ($rows as $r) {
                    $lastId = (int)$r['mcp_log_id'];
                    $hash = (string)($r['hash'] ?? '');

                    if ($hash === '') {
                        if ($chainStarted) {
                            return array_merge($out, ['ok' => false, 'first_bad_id' => $lastId, 'reason' => 'missing hash after the chain started']);
                        }

                        $out['legacy']++;
                        continue;
                    }

                    $chainStarted = true;

                    if ((string)$r['prev_hash'] !== $prev) {
                        return array_merge($out, ['ok' => false, 'first_bad_id' => $lastId, 'reason' => 'previous record missing or changed']);
                    }

                    $fields = [$r['date_added'], $r['ip'], $r['client'], $r['tool'], $r['arguments'], $r['status'], $r['message'], (string)(int)$r['duration_ms']];

                    if (!hash_equals(self::chain($prev, $fields), $hash)) {
                        return array_merge($out, ['ok' => false, 'first_bad_id' => $lastId, 'reason' => 'record content changed']);
                    }

                    $prev = $hash;
                    $out['checked']++;
                }
            } while (count($rows) === $batch);
        }

        $out['last_hash'] = $prev;
        $out['last_id'] = $lastId;

        $anchor = $this->anchor();

        if ($anchor && (int)$anchor['last_id'] > 0) {
            if ((int)$anchor['last_id'] > $lastId) {
                return array_merge($out, ['ok' => false, 'first_bad_id' => $lastId + 1, 'reason' => 'records removed from the end (anchor #' . (int)$anchor['last_id'] . ')']);
            }

            if ((int)$anchor['last_id'] === $lastId && !hash_equals((string)$anchor['last_hash'], $prev)) {
                return array_merge($out, ['ok' => false, 'first_bad_id' => $lastId, 'reason' => 'last record does not match the anchor']);
            }
        }

        return $out;
    }

    public function anchor(): ?array {
        try {
            return $this->db->row("SELECT * FROM " . $this->db->t(self::ANCHOR) . " WHERE `anchor_id` = 1");
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Filtered page of the live log, newest first.
     * @param array $f date_from, date_to (YYYY-MM-DD), range (session | day | 7d | 30d), tool, client, status (ok|error|denied), q (free text)
     */
    public function page(array $f, int $offset = 0, int $limit = 100): array {
        $where = ['1'];

        if (!empty($f['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['date_from'])) {
            $where[] = "`date_added` >= " . $this->db->q($f['date_from'] . ' 00:00:00');
        }

        if (!empty($f['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['date_to'])) {
            $where[] = "`date_added` <= " . $this->db->q($f['date_to'] . ' 23:59:59');
        }

        if (!empty($f['range']) && in_array($f['range'], ['session', 'day', '7d', '30d'], true)) {
            $where[] = '1' . (new LogExport($this->db))->range((string)$f['range'])[0];
        }

        if (!empty($f['tool'])) {
            $where[] = "`tool` = " . $this->db->q($f['tool']);
        }

        if (!empty($f['status']) && in_array($f['status'], ['ok', 'error', 'denied'], true)) {
            $where[] = "`status` = " . $this->db->q($f['status']);
        }

        if (!empty($f['client'])) {
            $where[] = "`client` LIKE " . $this->db->like($f['client']);
        }

        if (!empty($f['q'])) {
            $like = $this->db->like($f['q']);
            $where[] = "(`arguments` LIKE $like OR `message` LIKE $like OR `ip` LIKE $like)";
        }

        $w = implode(' AND ', $where);
        $limit = max(1, min(500, $limit));
        $cols = "`mcp_log_id`, `date_added`, `ip`, `client`, `tool`, `arguments`, `status`, `message`, `duration_ms`" . ($this->hasCaller() ? ", `caller`" : ", '' AS caller");
        $src = $this->db->t(self::TABLE); // the view shows recent (live) activity; archived entries stay in the export and the integrity check
        $total = (int)$this->db->value("SELECT COUNT(*) AS c FROM " . $src . " WHERE " . $w, 'c');
        $rows = $this->db->rows("SELECT " . $cols . " FROM " . $src . " WHERE " . $w . " ORDER BY `mcp_log_id` DESC LIMIT " . max(0, $offset) . ", " . $limit);

        foreach ($rows as &$r) {
            foreach (['arguments', 'message'] as $k) {
                if (function_exists('mb_strlen') && mb_strlen((string)$r[$k]) > 600) {
                    $r[$k] = mb_substr((string)$r[$k], 0, 600) . '…';
                }
            }
        }
        unset($r);

        $tools = array_map(function ($r) { return $r['tool']; }, $this->db->rows("SELECT DISTINCT `tool` FROM " . $src . " ORDER BY `tool`"));

        return ['total' => $total, 'offset' => $offset, 'rows' => $rows, 'tools' => $tools];
    }

    /**
     * Move records older than $days into the archive table (same ids and hashes, so the chain stays verifiable).
     * Nothing is ever deleted without first being copied.
     */
    /**
     * Delete the whole log - live, archive and the integrity anchor - back to zero. Unlike clear() this keeps nothing,
     * so export first if the records are needed. The next call starts a fresh hash chain.
     * @return int records removed (live + archive)
     */
    public function purge(): int {
        $n = (int)$this->db->value("SELECT COUNT(*) AS c FROM " . $this->db->t(self::TABLE), 'c');

        if ($this->db->tableExists(self::ARCHIVE)) {
            $n += (int)$this->db->value("SELECT COUNT(*) AS c FROM " . $this->db->t(self::ARCHIVE), 'c');
            $this->db->exec("DELETE FROM " . $this->db->t(self::ARCHIVE));
        }

        $this->db->exec("DELETE FROM " . $this->db->t(self::TABLE));

        if ($this->db->tableExists(self::ANCHOR)) {
            $this->db->exec("DELETE FROM " . $this->db->t(self::ANCHOR));
        }

        if ($this->db->tableExists(self::SETS)) {
            $this->db->exec("DELETE FROM " . $this->db->t(self::SETS));
        }

        return $n;
    }

    /**
     * Clear the live log by moving every record into the archive (same ids and hashes, so the chain stays verifiable
     * and the export keeps the full history). Nothing is deleted without first being copied.
     * @return int records moved
     */
    public function clear(string $by = ''): int {
        if (!$this->db->tableExists(self::ARCHIVE)) {
            return 0;
        }

        return $this->archiveUpTo((int)$this->db->value("SELECT MAX(`mcp_log_id`) AS m FROM " . $this->db->t(self::TABLE), 'm'), $by);
    }

    public function archive(int $days, string $by = ''): int {
        $days = max(0, $days);
        $cutoff = $this->db->q(date('Y-m-d H:i:s', time() - $days * 86400));
        $maxId = (int)$this->db->value("SELECT MAX(`mcp_log_id`) AS m FROM " . $this->db->t(self::TABLE) . " WHERE `date_added` < " . $cutoff, 'm');
        $lastId = (int)$this->db->value("SELECT MAX(`mcp_log_id`) AS m FROM " . $this->db->t(self::TABLE), 'm');

        return $this->archiveUpTo(min($maxId, $lastId - 1), $by); // always keep the newest record in the live table
    }

    /** Live rows kept when the log is archived on its own (the History screen and the exports read them fast). */
    const LIVE_ROWS = 50000;

    /**
     * Housekeeping (every ~200th log write): once the live log passes LIVE_ROWS, its oldest rows move to the archive 5000 at a time (one big
     * INSERT ... SELECT would lock the table for long). The hash chain is kept: rows are copied, compared, then removed.
     */
    public function autoArchive(): int {
        $n = (int)$this->db->value("SELECT COUNT(*) AS c FROM " . $this->db->t(self::TABLE), 'c');

        if ($n <= self::LIVE_ROWS) {
            return 0;
        }

        $minId = (int)$this->db->value("SELECT MIN(`mcp_log_id`) AS m FROM " . $this->db->t(self::TABLE), 'm');

        return $this->archiveUpTo($minId + min(5000, $n - self::LIVE_ROWS) - 1, 'auto');
    }

    /** Copy rows up to $maxId to the archive and remove them only when every one arrived unchanged; the archive is listed with who made it. */
    private function archiveUpTo(int $maxId, string $by = ''): int {
        if ($maxId <= 0) {
            return 0;
        }

        $span = $this->db->row("SELECT MIN(`mcp_log_id`) AS a, MAX(`mcp_log_id`) AS b, MIN(`date_added`) AS f, MAX(`date_added`) AS t FROM " . $this->db->t(self::TABLE) . " WHERE `mcp_log_id` <= " . $maxId);

        $this->db->exec("INSERT IGNORE INTO " . $this->db->t(self::ARCHIVE) . " SELECT * FROM " . $this->db->t(self::TABLE) . " WHERE `mcp_log_id` <= " . $maxId);
        $copied = (int)$this->db->value("SELECT COUNT(*) AS c FROM " . $this->db->t(self::ARCHIVE) . " a INNER JOIN " . $this->db->t(self::TABLE) . " l ON l.`mcp_log_id` = a.`mcp_log_id` AND l.`hash` = a.`hash` WHERE l.`mcp_log_id` <= " . $maxId, 'c');
        $expected = (int)$this->db->value("SELECT COUNT(*) AS c FROM " . $this->db->t(self::TABLE) . " WHERE `mcp_log_id` <= " . $maxId, 'c');

        if ($copied !== $expected) {
            return 0; // copy incomplete - do not remove anything
        }

        $moved = $this->db->exec("DELETE FROM " . $this->db->t(self::TABLE) . " WHERE `mcp_log_id` <= " . $maxId);

        if ($moved > 0 && $span !== null && $this->db->tableExists(self::SETS)) {
            $this->db->exec("INSERT INTO " . $this->db->t(self::SETS) . " SET `first_id` = " . (int)$span['a'] . ", `last_id` = " . (int)$span['b'] . ", `rows` = " . $moved
                . ", `date_from` = " . $this->db->q(substr((string)$span['f'], 0, 10)) . ", `date_to` = " . $this->db->q(substr((string)$span['t'], 0, 10))
                . ", `created_by` = " . $this->db->q(substr($by, 0, 64)) . ", `date_added` = NOW()");
        }

        return $moved;
    }

    /** The archives, newest first: number, the dates of the records it holds, who made it ('auto' = the hourly clean-up) and when. */
    public function archives(): array {
        if (!$this->db->tableExists(self::SETS)) {
            return [];
        }

        return $this->db->rows("SELECT `archive_id`, `first_id`, `last_id`, `rows`, `date_from`, `date_to`, `created_by`, `date_added` FROM " . $this->db->t(self::SETS) . " ORDER BY `archive_id` DESC");
    }

    /** One archive by its number, or null. */
    public function archiveSet(int $id): ?array {
        if (!$this->db->tableExists(self::SETS)) {
            return null;
        }

        return $this->db->row("SELECT * FROM " . $this->db->t(self::SETS) . " WHERE `archive_id` = " . $id);
    }
}
