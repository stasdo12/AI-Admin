<?php
namespace OcMcp;

/**
 * Fixed-window request counter in `{prefix}mcp_rate` (one row per bucket). Fails open if the table is missing.
 */
final class RateLimiter {
    const TABLE = 'mcp_rate';

    /** @var Db */
    private $db;
    /** @var int */
    private $window;

    public function __construct(Db $db, int $windowSeconds = 60) {
        $this->db = $db;
        $this->window = max(1, $windowSeconds);
    }

    /**
     * Count a hit; true when the bucket is still within $limit for the current window.
     * $failOpen: what to answer when the counter table is unusable (request limits fail open, the e-mail limit fails closed).
     */
    public function hit(string $bucket, int $limit, bool $failOpen = true): bool {
        if ($limit <= 0) {
            return true;
        }

        try {
            $now = time();
            $start = $now - ($now % $this->window);
            $key = $this->db->q(substr($bucket, 0, 128));

            $this->db->exec("INSERT INTO " . $this->db->t(self::TABLE) . " SET `bucket` = " . $key . ", `window_start` = " . $start . ", `hits` = 1"
                // a request computed in the previous window must not reset a newer window (GREATEST keeps the newest start)
                . " ON DUPLICATE KEY UPDATE `hits` = IF(`window_start` >= " . $start . ", `hits` + 1, 1), `window_start` = GREATEST(`window_start`, " . $start . ")");

            $hits = (int)$this->db->value("SELECT `hits` FROM " . $this->db->t(self::TABLE) . " WHERE `bucket` = " . $key, 'hits');

            if (mt_rand(1, 200) === 1) {
                $this->db->exec("DELETE FROM " . $this->db->t(self::TABLE) . " WHERE `window_start` < " . ($now - 3600));
            }

            return $hits <= $limit;
        } catch (\Throwable $e) {
            return $failOpen;
        }
    }

    /** Add $n hits at once (counting rows instead of requests). */
    public function add(string $bucket, int $n): void {
        if ($n <= 0) {
            return;
        }

        try {
            $now = time();
            $start = $now - ($now % $this->window);
            $this->db->exec("INSERT INTO " . $this->db->t(self::TABLE) . " SET `bucket` = " . $this->db->q(substr($bucket, 0, 128)) . ", `window_start` = " . $start . ", `hits` = " . $n
                . " ON DUPLICATE KEY UPDATE `hits` = IF(`window_start` >= " . $start . ", `hits` + " . $n . ", " . $n . "), `window_start` = GREATEST(`window_start`, " . $start . ")"); // like hit(): a late request never resets a newer window
        } catch (\Throwable $e) {
        }
    }

    /** Hits of a bucket in the current window. */
    public function used(string $bucket): int {
        try {
            $now = time();
            $row = $this->db->row("SELECT `hits`, `window_start` FROM " . $this->db->t(self::TABLE) . " WHERE `bucket` = " . $this->db->q(substr($bucket, 0, 128)));

            return ($row && (int)$row['window_start'] === $now - ($now % $this->window)) ? (int)$row['hits'] : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function retryAfter(): int {
        $now = time();

        return $this->window - ($now % $this->window);
    }
}
