<?php
namespace OcMcp;

/**
 * Numbers for the admin Overview dashboard. Every query is bounded by date (index on date_added) or by a small
 * table, and every block fails soft: a missing table gives zeros, never an error on the admin page.
 */
final class Stats {
    /** @var Db */
    private $db;

    public function __construct(Db $db) {
        $this->db = $db;
    }

    public function overview(int $days = 7): array {
        $out = [
            'calls_24h' => 0, 'errors_24h' => 0, 'denied_24h' => 0, 'calls_7d' => 0, 'clients_7d' => 0,
            'failed_logins_24h' => 0, 'last_call' => null, 'top_tools' => [], 'daily' => [],
            'connections' => 0, 'changes_7d' => 0, 'rows_7d' => 0, 'high_risk_7d' => 0, 'pending' => 0, 'next_pending' => null, 'rolled_back_7d' => 0, 'conflicts_7d' => 0,
            'mails_7d' => 0, 'connections_24h' => 0, 'session_tools' => [],
        ];
        $log = $this->db->t(Logger::TABLE);
        $since24 = $this->db->q(date('Y-m-d H:i:s', time() - 86400));
        $since7 = $this->db->q(date('Y-m-d H:i:s', time() - 7 * 86400));
        $sinceN = $this->db->q(date('Y-m-d 00:00:00', time() - ($days - 1) * 86400));
        $real = " AND `tool` NOT LIKE '\\_%' AND `tool` NOT LIKE 'oauth\\_%'"; // internal events (_rollback, oauth_register...) are not AI calls

        $this->soft(function () use (&$out, $log, $since24, $since7, $real) {
            $r = $this->db->row("SELECT COUNT(*) AS calls, SUM(`status` = 'error') AS errors, SUM(`status` = 'denied') AS denied FROM " . $log . " WHERE `date_added` >= " . $since24 . $real);
            $out['calls_24h'] = (int)($r['calls'] ?? 0);
            $out['errors_24h'] = (int)($r['errors'] ?? 0);
            $out['denied_24h'] = (int)($r['denied'] ?? 0);
            $out['failed_logins_24h'] = (int)$this->db->value("SELECT COUNT(*) AS c FROM " . $log . " WHERE `date_added` >= " . $since24 . " AND `tool` = '_oauth_login_failed'", 'c');
            $r = $this->db->row("SELECT COUNT(*) AS calls, COUNT(DISTINCT `client`) AS clients FROM " . $log . " WHERE `date_added` >= " . $since7 . $real);
            $out['calls_7d'] = (int)($r['calls'] ?? 0);
            $out['clients_7d'] = (int)($r['clients'] ?? 0);
            $out['top_tools'] = array_map(function ($r) { return ['tool' => $r['tool'], 'calls' => (int)$r['calls']]; },
                $this->db->rows("SELECT `tool`, COUNT(*) AS calls FROM " . $log . " WHERE `date_added` >= " . $since7 . $real . " GROUP BY `tool` ORDER BY calls DESC LIMIT 5"));
            $last = $this->db->row("SELECT `date_added`, `client`, `tool`, `status` FROM " . $log . " WHERE 1" . $real . " ORDER BY `mcp_log_id` DESC LIMIT 1");
            $out['last_call'] = $last ?: null;
        });

        $this->soft(function () use (&$out, $log, $sinceN, $days, $real) {
            $byDay = [];
            foreach ($this->db->rows("SELECT DATE(`date_added`) AS d, COUNT(*) AS calls, SUM(`status` <> 'ok') AS problems FROM " . $log . " WHERE `date_added` >= " . $sinceN . $real . " GROUP BY DATE(`date_added`)") as $r) {
                $byDay[$r['d']] = ['calls' => (int)$r['calls'], 'problems' => (int)$r['problems']];
            }
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = date('Y-m-d', time() - $i * 86400);
                $out['daily'][] = ['date' => $d] + ($byDay[$d] ?? ['calls' => 0, 'problems' => 0]);
            }
        });

        $this->soft(function () use (&$out) {
            $out['connections'] = (int)$this->db->value("SELECT COUNT(*) AS c FROM " . $this->db->t('mcp_oauth_token') . " WHERE `revoked` = 0 AND `refresh_expires` > NOW()", 'c');
        });

        $this->soft(function () use (&$out, $since7) {
            $ch = $this->db->t('mcp_change');
            $r = $this->db->row("SELECT COUNT(DISTINCT `batch_id`) AS batches, COUNT(*) AS rows_n, COUNT(DISTINCT IF(`high_risk` = 1, `batch_id`, NULL)) AS hr FROM " . $ch . " WHERE `date_added` >= " . $since7);
            $out['changes_7d'] = (int)($r['batches'] ?? 0);
            $out['rows_7d'] = (int)($r['rows_n'] ?? 0);
            $out['high_risk_7d'] = (int)($r['hr'] ?? 0);
            $r = $this->db->row("SELECT COUNT(DISTINCT `batch_id`) AS n, MIN(`execute_after`) AS next FROM " . $ch . " WHERE `status` = 'pending'");
            $out['pending'] = (int)($r['n'] ?? 0);
            $out['next_pending'] = $r['next'] ?? null;
            $r = $this->db->row("SELECT COUNT(DISTINCT IF(`status` = 'rolled_back', `batch_id`, NULL)) AS rb, SUM(`status` = 'conflict') AS cf FROM " . $ch . " WHERE `date_added` >= " . $since7);
            $out['rolled_back_7d'] = (int)($r['rb'] ?? 0);
            $out['conflicts_7d'] = (int)($r['cf'] ?? 0);
        });

        // how many times the AI connected in 24 h: sessions of a connection, a pause over 20 min starts a new one (as the log export counts them)
        $this->soft(function () use (&$out, $log, $since24, $real) {
            $last = [];
            $who = $this->hasCaller($log) ? "IF(`caller` <> '', `caller`, CONCAT(`client`, '|', `ip`))" : "CONCAT(`client`, '|', `ip`)";

            foreach ($this->db->rows("SELECT " . $who . " AS who, `date_added` FROM " . $log . " WHERE `date_added` >= " . $since24 . $real . " ORDER BY `mcp_log_id` LIMIT 50000") as $r) {
                $t = (int)strtotime((string)$r['date_added']);

                if (!isset($last[$r['who']]) || $t - $last[$r['who']] > LogExport::SESSION_GAP) {
                    $out['connections_24h']++;
                }

                $last[$r['who']] = $t;
            }
        });

        // the latest session of the AI: the newest connection back to its first pause over 20 min; its last five tools with how often each ran
        $this->soft(function () use (&$out, $log, $real) {
            $newest = $this->db->row("SELECT * FROM " . $log . " WHERE 1" . $real . " ORDER BY `mcp_log_id` DESC LIMIT 1");

            if ($newest === null) {
                return;
            }

            $same = $this->hasCaller($log) && (string)$newest['caller'] !== '' ? "`caller` = " . $this->db->q((string)$newest['caller'])
                : "`client` = " . $this->db->q((string)$newest['client']) . " AND `ip` = " . $this->db->q((string)$newest['ip']);
            $prev = (int)strtotime((string)$newest['date_added']);
            $tools = [];

            foreach ($this->db->rows("SELECT `tool`, `date_added` FROM " . $log . " WHERE " . $same . $real . " AND `mcp_log_id` <= " . (int)$newest['mcp_log_id'] . " ORDER BY `mcp_log_id` DESC LIMIT 5000") as $r) {
                $t = (int)strtotime((string)$r['date_added']);

                if ($prev - $t > LogExport::SESSION_GAP) {
                    break;
                }

                $tools[$r['tool']] = ($tools[$r['tool']] ?? 0) + 1; // newest first: the order of the keys is the order of last use
                $prev = $t;
            }

            foreach (array_slice($tools, 0, 5, true) as $tool => $calls) {
                $out['session_tools'][] = ['tool' => (string)$tool, 'calls' => $calls];
            }
        });

        $this->soft(function () use (&$out, $since7) {
            $out['mails_7d'] = (int)$this->db->value("SELECT COALESCE(SUM(`sent`), 0) AS c FROM " . $this->db->t('mcp_mail') . " WHERE `date_added` >= " . $since7, 'c');
        });

        return $out;
    }

    private function hasCaller(string $log): bool {
        return (bool)$this->db->rows("SHOW COLUMNS FROM " . $log . " LIKE 'caller'");
    }

    private function soft(callable $fn): void {
        try {
            $fn();
        } catch (\Throwable $e) {
        }
    }
}
