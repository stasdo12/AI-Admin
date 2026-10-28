<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class LowStock extends Tool {
    public function name(): string {
        return 'low_stock';
    }

    public function tables(): array {
        return ['product', 'product_description'];
    }

    public function fieldsKey(): ?string {
        return 'product_id';
    }

    public function describe(): array {
        return [
            'title'       => 'Low / out of stock products',
            'description' => 'Enabled products whose quantity is at or below a threshold (default 5), lowest first. Useful for restock planning.',
            'inputSchema' => self::schema([
                'threshold'        => self::prop('integer', 'Quantity threshold (default 5).'),
                'include_disabled' => self::prop('boolean', 'Also include disabled products (default false).'),
                'store_id'         => self::prop('integer', 'Only products shown in this store.'),
                'limit'            => self::prop('integer', 'Max rows 1-200 (default 50).'),
                'offset'           => self::prop('integer', 'Offset (default 0).'),
            ]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $db = $c->db;
        $L = (int)$c->languageId;
        $threshold = (int)$this->int($a, 'threshold', 5);
        $includeDisabled = (bool)$this->bool($a, 'include_disabled', false);
        $limit = $this->limit($a, 50);
        $offset = $this->offset($a);

        $where = "p.`quantity` <= " . $threshold . ($includeDisabled ? '' : " AND p.`status` = 1");

        if (array_key_exists('store_id', $a) && $a['store_id'] !== null && $a['store_id'] !== '') {
            $where .= " AND p.`product_id` IN (SELECT `product_id` FROM " . $db->t('product_to_store') . " WHERE `store_id` = " . $c->storeIds($a['store_id'], 'store_id')[0] . ")";
        }

        $total = (int)$db->value("SELECT COUNT(*) AS c FROM " . $db->t('product') . " p WHERE " . $where, 'c');
        $rows = $db->rows("SELECT p.`product_id`, pd.`name`, p.`model`, " . $c->skuExpr() . " AS sku, p.`quantity`, p.`price`, p.`status`, p.`subtract`, ss.`name` AS stock_status"
            . " FROM " . $db->t('product') . " p"
            . " LEFT JOIN " . $db->t('product_description') . " pd ON pd.`product_id` = p.`product_id` AND pd.`language_id` = " . $L
            . " LEFT JOIN " . $db->t('stock_status') . " ss ON ss.`stock_status_id` = p.`stock_status_id` AND ss.`language_id` = " . $L
            . " WHERE " . $where . " ORDER BY p.`quantity` ASC, pd.`name` ASC LIMIT " . $offset . ", " . $limit);

        $items = [];

        foreach ($rows as $r) {
            $items[] = [
                'product_id'     => (int)$r['product_id'],
                'name'           => (string)$r['name'],
                'model'          => (string)$r['model'],
                'sku'            => (string)$r['sku'],
                'quantity'       => (int)$r['quantity'],
                'price'          => (float)$r['price'],
                'status'         => (bool)$r['status'],
                'subtract_stock' => (bool)$r['subtract'],
                'stock_status'   => (string)$r['stock_status'],
            ];
        }

        return ToolResult::data(['threshold' => $threshold, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'items' => $items], "$total product(s) at or below quantity $threshold.");
    }
}
