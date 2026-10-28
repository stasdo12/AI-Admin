<?php
namespace OcMcp;

/**
 * FREE / demo build: STUB. The real change-journal + rollback engine (before/after row snapshots,
 * batch diffing, undo on MyISAM, photo-trash handling) is a paid-only feature and ships only in the
 * paid build. This read-only distribution never writes, so nothing is ever journaled.
 *
 * The class, its constants and every method signature are kept so the core (Server, Context,
 * Installer, ImageStore, the controllers) compiles and runs unchanged - the bodies are inert.
 * The "History / Data changes" admin tab stays visible but is disabled in the free build.
 */
final class ChangeJournal {
    const TABLE = 'mcp_change';
    const BATCHES = 'mcp_change_batch';
    const MAX_ROWS = 500;

    const HIGH_RISK_TABLES = [
        'customer', 'address', 'customer_transaction', 'customer_reward', 'customer_group', 'order', 'order_product', 'order_total', 'order_history', 'order_option',
        'return', 'return_history', 'voucher', 'voucher_history', 'coupon', 'coupon_product', 'coupon_category', 'product', 'category', 'manufacturer',
        'information', 'review', 'setting', 'currency',
    ];

    const KEEP_DAYS = 30;
    const VOLATILE = ['viewed', 'rating'];

    /** @var Db */
    private $db;
    /** @var string */
    private $batch;

    public function __construct(Db $db, string $client = '', string $tool = '', int $deleteDelayMinutes = 10, ?string $batch = null) {
        $this->db = $db;
        $this->batch = $batch ?: '';
    }

    public function setCaller(string $caller): self {
        return $this;
    }

    public function batch(): string {
        return $this->batch;
    }

    public function number(): int {
        return 0;
    }

    public function numberIfAny(): int {
        return 0;
    }

    public function numberOf(string $batch): int {
        return 0;
    }

    public function resolve(string $ref): string {
        return $ref;
    }

    public static function isHighRisk(string $table): bool {
        return in_array($table, self::HIGH_RISK_TABLES, true);
    }

    public function deleteDelay(): int {
        return 0;
    }

    public function pk(string $table): array {
        return [];
    }

    public function snapshot(string $table, string $where): array {
        return [];
    }

    public function commit(array $snap, string $extraWhere = ''): int {
        return 0;
    }

    public function prune(): int {
        return 0;
    }

    public function sessionStart(): string {
        return '';
    }

    public function runDue(): int {
        return 0;
    }

    public function refreshAfter(string $table, array $pk, array $row): void {
    }

    public function cancel(string $batch, int $changeId = 0): int {
        return 0;
    }

    public function rollback(string $batch, bool $force = false): array {
        return ['ok' => false, 'undone' => 0, 'reason' => 'rollback is a paid feature'];
    }

    public static function changedColumns(array $before, array $after): array {
        return [];
    }

    public function batches(int $offset = 0, int $limit = 50, array $filter = []): array {
        return ['total' => 0, 'open' => 0, 'rows' => []];
    }

    public function counts(array $filter = []): array {
        return ['total' => 0, 'open' => 0, 'done' => 0, 'pending' => 0];
    }

    public function connections(): array {
        return [];
    }

    public function rollbackMany(array $filter, bool $force = false): array {
        return ['ok' => false, 'undone' => 0, 'reason' => 'rollback is a paid feature'];
    }

    public function details(string $batch, int $limit = 0, int $afterId = 0): array {
        return [];
    }

    public function eachDetail(string $batch, callable $fn): void {
    }

    public function detailCount(string $batch): int {
        return 0;
    }

    public function detailsForDisplay(string $batch, TablePolicy $policy, int $limit = 500): array {
        return [];
    }
}
