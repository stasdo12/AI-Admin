<?php
namespace OcMcp;

/**
 * One yes for a series of changes of one kind. With "ask before changes" every call needs its own preview and
 * approval: 482 products in batches of 45 were 22 extra calls, galleries one product at a time 114. A preview that
 * declares series_total hands out a series code; after the user agrees to the whole series, the next calls of the same
 * tool, for the same kind of pages (entity, new or existing), from the same connection, within an hour and up to that
 * many pages, are applied without another preview.
 */
final class Series {
    const TABLE = 'mcp_series';
    const TOOLS = ['save_content', 'set_images'];
    const TTL_MINUTES = 60;
    const MAX_TOTAL = 5000;

    /** What kind of pages a call changes: "product:new", "product:edit" ... (order and repeats do not matter). */
    public static function signature(string $tool, array $args): string {
        $kinds = [];

        foreach (isset($args['items']) && is_array($args['items']) ? $args['items'] : [$args] as $it) {
            if (is_array($it)) {
                $kinds[] = (string)($it['entity'] ?? 'product') . ':' . (empty($it['id']) ? 'new' : 'edit');
            }
        }

        $kinds = array_values(array_unique($kinds));
        sort($kinds);

        return $tool . '|' . implode(',', $kinds);
    }

    public static function pages(array $args): int {
        return isset($args['items']) && is_array($args['items']) ? count($args['items']) : 1;
    }

    /** A new series for a preview; null when the store has no series table yet (the admin page creates it). */
    public static function create(Db $db, string $tool, string $caller, array $args, int $total): ?string {
        if (!in_array($tool, self::TOOLS, true) || $total < 2 || !$db->tableExists(self::TABLE)) {
            return null;
        }

        $code = 'S-' . bin2hex(random_bytes(8));
        $db->exec("INSERT INTO " . $db->t(self::TABLE) . " SET `code` = " . $db->q($code) . ", `tool` = " . $db->q($tool) . ", `caller` = " . $db->q(substr($caller, 0, 128))
            . ", `kind` = " . $db->q(substr(self::signature($tool, $args), 0, 255)) . ", `remaining` = " . min($total, self::MAX_TOTAL)
            . ", `expires` = DATE_ADD(NOW(), INTERVAL " . self::TTL_MINUTES . " MINUTE), `date_added` = NOW()");

        if (mt_rand(1, 50) === 1) {
            $db->exec("DELETE FROM " . $db->t(self::TABLE) . " WHERE `expires` < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        }

        return $code;
    }

    /** Use the series code for this call: true when it covers it (its pages are taken off the series at once). */
    /** Give back the pages a call spent when it changed nothing (an error, a crash rolled back). */
    public static function refund(Db $db, string $tool, string $code, array $args): void {
        $db->exec("UPDATE " . $db->t(self::TABLE) . " SET `remaining` = `remaining` + " . self::pages($args) . " WHERE `code` = " . $db->q($code) . " AND `tool` = " . $db->q($tool));
    }

    public static function consume(Db $db, string $tool, string $caller, array $args, string $code): bool {
        if (!preg_match('/^S-[0-9a-f]{16}$/', $code) || !in_array($tool, self::TOOLS, true) || !$db->tableExists(self::TABLE)) {
            return false;
        }

        $n = self::pages($args);

        // one statement: two calls at the same moment cannot both spend the last pages
        return $db->exec("UPDATE " . $db->t(self::TABLE) . " SET `remaining` = `remaining` - " . $n . " WHERE `code` = " . $db->q($code) . " AND `tool` = " . $db->q($tool)
            . " AND `caller` = " . $db->q(substr($caller, 0, 128)) . " AND `kind` = " . $db->q(substr(self::signature($tool, $args), 0, 255))
            . " AND `remaining` >= " . $n . " AND `expires` > NOW()") === 1;
    }
}
