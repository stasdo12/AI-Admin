<?php
namespace OcMcp;

/**
 * Streams the call log as a downloadable file: "<Store name>_mcp-log_<date>.txt" (or .csv).
 * The TXT export ends with the chain verification result and the last hash, so a saved copy can be compared later.
 */
final class LogExport {
    /** @var Db */
    private $db;

    public function __construct(Db $db) {
        $this->db = $db;
    }

    public static function filename(string $storeName, string $ext): string {
        $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ie','ж'=>'zh','з'=>'z','и'=>'y','і'=>'i','ї'=>'i','й'=>'i','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ь'=>'','ю'=>'iu','я'=>'ia','ы'=>'y','э'=>'e','ё'=>'e','ъ'=>''];
        $name = function_exists('mb_strtolower') ? mb_strtolower($storeName, 'UTF-8') : strtolower($storeName);
        $name = strtr($name, $map);
        $name = trim(preg_replace('/[^a-z0-9]+/', '-', $name), '-');

        return ($name !== '' ? $name : 'store') . '_mcp-log_' . date('Y-m-d') . '.' . $ext;
    }

    /** A pause longer than this starts a new session of a connection. */
    const SESSION_GAP = 1200;

    /**
     * @param string $range all | session (the latest session: one connection, no pause over 20 min) | day (last 24 h) | 7d | 30d | archive-N (one archive of the History tab)
     */
    public function send(string $storeName, string $format = 'txt', string $range = 'all'): void {
        $csv = $format === 'csv';
        $file = self::filename($storeName, $csv ? 'csv' : 'txt');
        [$where, $suffix] = $this->range($range);

        if ($suffix !== '') {
            $file = str_replace('_mcp-log_', '_mcp-log-' . $suffix . '_', $file);
        }

        if (!headers_sent()) {
            header('Content-Type: ' . ($csv ? 'text/csv' : 'text/plain') . '; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $file . '"');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($csv) {
            fwrite($out, "\xEF\xBB\xBF");
            Csv::put($out, ['id', 'date', 'ip', 'client', 'tool', 'arguments', 'status', 'message', 'duration_ms', 'hash', 'session']);
        } else {
            fwrite($out, "MCP call log - " . $storeName . " - exported " . date('c') . "\n" . str_repeat('=', 78) . "\n");
        }

        $last = 0;
        $tables = $this->db->tableExists(Logger::ARCHIVE) ? [Logger::ARCHIVE, Logger::TABLE] : [Logger::TABLE];

        if (strpos($range, 'archive-') === 0) {
            $tables = [Logger::ARCHIVE]; // an archive lives in the archive table only
        }
        $sessions = []; // connection => [session number, time of its last call]
        $count = 0;

        foreach ($tables as $table) {
        do {
            $rows = $this->db->rows("SELECT * FROM " . $this->db->t($table) . " WHERE `mcp_log_id` > " . $last . $where . " ORDER BY `mcp_log_id` ASC LIMIT 1000");

            foreach ($rows as $r) {
                $last = (int)$r['mcp_log_id'];
                $who = self::who($r);
                $t = (int)strtotime((string)$r['date_added']);

                if (!isset($sessions[$who]) || $t - $sessions[$who][1] > self::SESSION_GAP) {
                    $sessions[$who] = [++$count, $t];
                }

                $sessions[$who][1] = $t;
                $session = $sessions[$who][0];

                if ($csv) {
                    Csv::put($out, [$r['mcp_log_id'], $r['date_added'], $r['ip'], $r['client'], $r['tool'], $r['arguments'], $r['status'], $r['message'], $r['duration_ms'], $r['hash'] ?? '', $session]);
                } else {
                    fwrite($out, sprintf("#%d  %s  %-8s %s  %s  (%d ms)\n    client: %s   session: %d\n    args:   %s\n%s",
                        $r['mcp_log_id'], $r['date_added'], strtoupper((string)$r['status']), $r['tool'], $r['ip'], (int)$r['duration_ms'],
                        $r['client'] !== '' ? $r['client'] : '-', $session, $r['arguments'] !== '' ? $r['arguments'] : '-',
                        $r['message'] !== '' ? "    note:   " . $r['message'] . "\n" : ''));
                }
            }
        } while (count($rows) === 1000);
        }

        if (!$csv) {
            $v = (new Logger($this->db))->verify();
            fwrite($out, str_repeat('=', 78) . "\nIntegrity: " . ($v['ok'] ? 'OK' : 'BROKEN at #' . $v['first_bad_id'] . ' (' . $v['reason'] . ')')
                . ", chained records: " . $v['checked'] . ", legacy records: " . $v['legacy'] . "\nLast hash: " . ($v['last_hash'] !== '' ? $v['last_hash'] : '-') . "\n");
        }

        fclose($out);
    }

    /** One connection: the name the owner gave it (caller), else the client and address. */
    private static function who(array $r): string {
        return (string)($r['caller'] ?? '') !== '' ? 'c:' . $r['caller'] : 'a:' . $r['client'] . '|' . $r['ip'];
    }

    /** SQL condition and file name part of a range (also the period filter of the log on the History tab). */
    public function range(string $range): array {
        if ($range === 'day') {
            return [" AND `date_added` >= DATE_SUB(NOW(), INTERVAL 1 DAY)", 'last-24h'];
        }

        if ($range === '7d' || $range === '30d') {
            return [" AND `date_added` >= DATE_SUB(NOW(), INTERVAL " . (int)$range . " DAY)", 'last-' . $range];
        }

        if (preg_match('/^archive-(\d+)$/', $range, $m)) {
            $set = (new Logger($this->db))->archiveSet((int)$m[1]);

            return $set === null ? [' AND 0', 'archive'] : [" AND `mcp_log_id` BETWEEN " . (int)$set['first_id'] . " AND " . (int)$set['last_id'], 'archive-' . (int)$m[1]];
        }

        if ($range !== 'session') {
            return ['', ''];
        }

        // the connection of the newest call (sign-in rows have no connection yet), back to its first pause over 20 min
        $hasCaller = $this->db->rows("SHOW COLUMNS FROM " . $this->db->t(Logger::TABLE) . " LIKE 'caller'") !== [];
        $newest = $this->db->row("SELECT * FROM " . $this->db->t(Logger::TABLE) . " WHERE `tool` NOT LIKE 'oauth%' AND `tool` <> '_auth' ORDER BY `mcp_log_id` DESC LIMIT 1");

        if ($newest === null) {
            return [' AND 0', 'session'];
        }

        $same = $hasCaller && (string)$newest['caller'] !== '' ? "`caller` = " . $this->db->q((string)$newest['caller'])
            : "`client` = " . $this->db->q((string)$newest['client']) . " AND `ip` = " . $this->db->q((string)$newest['ip']);
        $first = (int)$newest['mcp_log_id'];
        $prev = (int)strtotime((string)$newest['date_added']);

        foreach ($this->db->rows("SELECT `mcp_log_id`, `date_added` FROM " . $this->db->t(Logger::TABLE) . " WHERE " . $same . " AND `mcp_log_id` <= " . $first . " ORDER BY `mcp_log_id` DESC LIMIT 20000") as $r) {
            $t = (int)strtotime((string)$r['date_added']);

            if ($prev - $t > self::SESSION_GAP) {
                break;
            }

            $first = (int)$r['mcp_log_id'];
            $prev = $t;
        }

        return [" AND " . $same . " AND `mcp_log_id` BETWEEN " . $first . " AND " . (int)$newest['mcp_log_id'], 'session'];
    }
}
