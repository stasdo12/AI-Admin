<?php
namespace OcMcp;

/**
 * The same write request twice must not change the store twice. A client that lost the answer (dropped connection,
 * timeout) and repeats "create the product" gets the first result back instead of a second product.
 *
 * Key = connection + tool + the idempotency_key the AI passes, or, for creating calls, the request itself; it holds
 * for 10 minutes. The first request claims the key in ONE statement (INSERT IGNORE, affected rows = 1), so of two
 * parallel identical requests only one runs; the other waits for its result. A failed call frees the key.
 */
final class Idempotency {
    const TABLE = 'mcp_idem';
    const TTL = 600;
    const WAIT_SECONDS = 15;

    /** @var Db */
    private $db;

    public function __construct(Db $db) {
        $this->db = $db;
    }

    /** Who is calling: stable across OAuth token refreshes (client + user), else the static token + IP. */
    public static function caller(Context $c): string {
        if (is_array($c->grant) && isset($c->grant['client_id'])) {
            return 'oauth:' . $c->grant['client_id'] . ':' . ($c->grant['user_id'] ?? 0);
        }

        return 'token:' . $c->ip;
    }

    /** Where the next portion of a long run starts (orders, bulk products, CSV import): the same job, not the same call. */
    const PORTION_KEYS = ['resume_after', 'continue_after_product_id', 'continue_from_row', 'continue_batch'];

    /** Key for this call, or null when it is not protected. */
    public static function key(string $caller, string $tool, array $args, bool $auto): ?string {
        $explicit = isset($args['idempotency_key']) && is_scalar($args['idempotency_key']) ? trim((string)$args['idempotency_key']) : '';

        if ($explicit !== '') {
            // the next portion of a large change is a new write, even when the AI repeats its key
            $portion = '';

            foreach (self::PORTION_KEYS as $k) {
                $portion .= isset($args[$k]) && is_scalar($args[$k]) ? '|' . $k . ':' . $args[$k] : '';
            }

            return hash('sha256', $caller . '|' . $tool . '|key|' . mb_substr($explicit, 0, 200) . $portion);
        }

        if (!$auto) {
            return null;
        }

        unset($args['idempotency_key']);

        return hash('sha256', $caller . '|' . $tool . '|args|' . self::canonical($args));
    }

    /**
     * Run $fn once per key. Returns [result array (MCP tools/call result), replayed?].
     * @param callable $fn () => ToolResult
     */
    public function run(string $key, string $tool, callable $fn, int $attempt = 0): array {
        $t = $this->db->t(self::TABLE);
        $q = $this->db->q($key);

        if (mt_rand(1, 50) === 1) {
            $this->db->exec("DELETE FROM " . $t . " WHERE `expires` < NOW()");
        }

        // an expired claim may be taken over: delete it only if it is still the expired one (guarded)
        $this->db->exec("DELETE FROM " . $t . " WHERE `key_hash` = " . $q . " AND `expires` < NOW()");
        $claimed = $this->db->exec("INSERT IGNORE INTO " . $t . " SET `key_hash` = " . $q . ", `tool` = " . $this->db->q($tool) . ", `status` = 'running', `result` = '', `date_added` = NOW(), `expires` = NOW() + INTERVAL " . self::TTL . " SECOND") === 1;

        if ($claimed) {
            try {
                /** @var ToolResult $r */
                $r = $fn();
            } catch (\Throwable $e) {
                $this->db->exec("DELETE FROM " . $t . " WHERE `key_hash` = " . $q . " AND `status` = 'running'");
                throw $e;
            }

            if ($r->isError) {
                $this->db->exec("DELETE FROM " . $t . " WHERE `key_hash` = " . $q . " AND `status` = 'running'"); // a failed call may be retried
            } else {
                $this->db->exec("UPDATE " . $t . " SET `status` = 'done', `result` = " . $this->db->q((string)json_encode($r->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION))
                    . " WHERE `key_hash` = " . $q . " AND `status` = 'running'");
            }

            return [$r, false];
        }

        $deadline = microtime(true) + self::WAIT_SECONDS;

        do {
            $row = $this->db->row("SELECT `status`, `result`, `date_added` FROM " . $t . " WHERE `key_hash` = " . $q);

            if ($row === null) {
                if ($attempt >= 2) {
                    return [$fn(), false]; // the key table does not work: never block a write because of it
                }

                return $this->run($key, $tool, $fn, $attempt + 1); // the first call failed and freed the key: this one does the work
            }

            if ($row['status'] === 'done') {
                $prev = json_decode((string)$row['result'], true);
                $r = new ToolResult();
                $r->content = $prev['content'] ?? [];
                $r->structured = $prev['structuredContent'] ?? null;
                $note = 'The same request was already done at ' . $row['date_added'] . ': this is its result, nothing was changed again. To really do it once more, change the request or pass a new idempotency_key.';
                array_unshift($r->content, ['type' => 'text', 'text' => $note]);

                return [$r, true];
            }

            usleep(300000);
        } while (microtime(true) < $deadline);

        return [ToolResult::error('The same request is still running (started a moment ago). Wait and check the result with list_changes instead of sending it again.'), true];
    }

    /** Arguments in a stable form: key order and number formats do not make two equal requests different. */
    public static function canonical($v): string {
        if (is_array($v)) {
            $list = array_keys($v) === range(0, count($v) - 1);

            if (!$list) {
                ksort($v);
            }

            $parts = [];

            foreach ($v as $k => $x) {
                $parts[] = ($list ? '' : json_encode((string)$k) . ':') . self::canonical($x);
            }

            return ($list ? '[' : '{') . implode(',', $parts) . ($list ? ']' : '}');
        }

        if (is_string($v)) {
            return json_encode(trim($v), JSON_UNESCAPED_UNICODE);
        }

        return json_encode(is_float($v) && floor($v) == $v ? (int)$v : $v);
    }
}
