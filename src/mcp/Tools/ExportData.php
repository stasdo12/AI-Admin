<?php
namespace OcMcp\Tools;

use OcMcp\AccessDenied;
use OcMcp\Context;
use OcMcp\DataExport;
use OcMcp\TablePolicy;
use OcMcp\Tool;
use OcMcp\ToolResult;

/**
 * A download link for the whole catalog, orders or customers as one CSV file - for exports too large to list in the
 * chat ("give me all products in a file"). The file is built when the owner opens the link and obeys the Access tab.
 */
final class ExportData extends Tool {
    public function name(): string {
        return 'export_data';
    }

    public function level(): string {
        return self::LEVEL_READ;
    }

    /** No fixed table: the entity is an argument and its section is checked in run() (so the generic gate does not block). */
    public function tables(): array {
        return [];
    }

    public function autoIdempotent(array $args): bool {
        return false; // each call makes a fresh link; an expired one must not be replayed
    }

    public function describe(): array {
        return [
            'title'       => 'Export to a CSV file',
            'description' => 'A download link for a CSV of all products, orders or customers, for exports too big to list in the chat. '
                . 'Give the user the link; the file opens in Excel. Filters per entity: products {category_id, manufacturer_id, status}, orders {order_status_id, date_from, date_to}, customers {customer_group_id, newsletter}; any: store_id. Reads the section like the list tools; hidden fields are left out.',
            'inputSchema' => self::schema([
                'entity'  => self::prop('string', 'What to export.', ['enum' => DataExport::ENTITIES]),
                'filters' => self::prop('object', 'Optional conditions (see the description).'),
                'minutes' => self::prop('integer', 'How long the link works (5-120 minutes, default 60).'),
            ], ['entity']),
            'annotations' => self::annotations(true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        if ($c->downloadUrl === null) {
            return ToolResult::error('Downloads are not available in this store setup.');
        }

        $entity = $this->enum($a, 'entity', DataExport::ENTITIES, '');

        if ($entity === '') {
            return ToolResult::error('entity: one of ' . implode(', ', DataExport::ENTITIES) . '.');
        }

        $denial = DataExport::denial($c, $entity);

        if ($denial === null) {
            $table = DataExport::TABLE[$entity];

            return AccessDenied::result(AccessDenied::table($table, 'export ' . $entity, $c->policy()->level($table), TablePolicy::READ));
        }

        if ($denial !== '') {
            return ToolResult::error($denial);
        }

        $filters = isset($a['filters']) && is_array($a['filters']) ? $a['filters'] : [];

        if (isset($filters['store_id']) && $filters['store_id'] !== '' && $filters['store_id'] !== null) {
            $filters['store_id'] = $c->storeIds($filters['store_id'], 'filters.store_id')[0];
        }
        $minutes = (int)$this->int($a, 'minutes', 60, 5, DataExport::MAX_MINUTES);
        $total = DataExport::count($c, $entity, $filters);
        $token = DataExport::sign(['e' => $entity, 'f' => $filters, 'exp' => time() + $minutes * 60], DataExport::key($c));
        $url = (string)call_user_func($c->downloadUrl, $token);

        return ToolResult::data(
            ['entity' => $entity, 'rows' => $total, 'url' => $url, 'format' => 'csv', 'expires_in_minutes' => $minutes],
            "Export ready: $total $entity. Send the user this link to download the CSV (opens in Excel, works for $minutes minutes): $url"
        );
    }
}
