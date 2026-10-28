<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class SalesReport extends Tool {
    public function name(): string {
        return 'sales_report';
    }

    public function tables(): array {
        return ['order', 'order_product'];
    }

    public function describe(): array {
        return [
            'title'       => 'Sales report',
            'description' => 'Sales statistics for a period: orders, revenue, average order value, items sold, grouped by day/week/month; plus breakdown by status and top-selling products. Default period: last 30 days, all non-missing statuses. Amounts are in the store default currency.',
            'inputSchema' => self::schema([
                'date_from'        => self::prop('string', 'Start date YYYY-MM-DD (default: 30 days ago).'),
                'date_to'          => self::prop('string', 'End date YYYY-MM-DD inclusive (default: today).'),
                'group_by'         => self::prop('string', 'Granularity of the time series.', ['enum' => ['day', 'week', 'month']]),
                'order_status_ids' => self::prop('array', 'Only count orders in these status IDs (default: all statuses > 0; use complete/processing IDs from store_info for "real" sales).', ['items' => ['type' => 'integer']]),
                'top_products'     => self::prop('integer', 'How many top products to return (default 10, max 50).'),
                'store_id'         => self::prop('integer', 'Store ID (multi-store).'),
            ]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $db = $c->db;
        $L = (int)$c->languageId;
        $to = $this->date($a, 'date_to', date('Y-m-d'));
        $from = $this->date($a, 'date_from', date('Y-m-d', strtotime($to . ' -30 days')));
        $groupBy = $this->enum($a, 'group_by', ['day', 'week', 'month'], 'day');
        $topN = (int)$this->int($a, 'top_products', 10, 0, 50);

        $where = ["o.`date_added` >= " . $db->q($from . ' 00:00:00'), "o.`date_added` <= " . $db->q($to . ' 23:59:59')];

        if (!empty($a['order_status_ids']) && is_array($a['order_status_ids'])) {
            $where[] = "o.`order_status_id` IN (" . $db->ints($a['order_status_ids']) . ")";
        } else {
            $where[] = "o.`order_status_id` > 0";
        }

        $storeId = $this->int($a, 'store_id');

        if ($storeId !== null) {
            $where[] = "o.`store_id` = " . $storeId;
        }

        $w = " WHERE " . implode(' AND ', $where);

        $period = ['day' => "DATE_FORMAT(o.`date_added`, '%Y-%m-%d')", 'week' => "DATE_FORMAT(o.`date_added`, '%x-W%v')", 'month' => "DATE_FORMAT(o.`date_added`, '%Y-%m')"][$groupBy];

        $series = $db->rows("SELECT " . $period . " AS period, COUNT(*) AS orders, ROUND(SUM(o.`total`), 2) AS revenue, ROUND(AVG(o.`total`), 2) AS avg_order"
            . " FROM " . $db->t('order') . " o" . $w . " GROUP BY period ORDER BY period");

        $itemsByPeriod = [];

        foreach ($db->rows("SELECT " . $period . " AS period, SUM(op.`quantity`) AS items FROM " . $db->t('order_product') . " op INNER JOIN " . $db->t('order') . " o ON o.`order_id` = op.`order_id`" . $w . " GROUP BY period") as $r) {
            $itemsByPeriod[(string)$r['period']] = (int)$r['items'];
        }

        foreach ($series as &$row) {
            $row['items'] = $itemsByPeriod[(string)$row['period']] ?? 0;
        }
        unset($row);

        $summary = $db->row("SELECT COUNT(*) AS orders, ROUND(COALESCE(SUM(o.`total`), 0), 2) AS revenue, ROUND(COALESCE(AVG(o.`total`), 0), 2) AS avg_order, COUNT(DISTINCT o.`customer_id`) AS customers FROM " . $db->t('order') . " o" . $w);
        $items = array_sum($itemsByPeriod); // already counted per period: no second pass over the order lines

        $byStatus = $db->rows("SELECT o.`order_status_id`, os.`name` AS status, COUNT(*) AS orders, ROUND(SUM(o.`total`), 2) AS revenue FROM " . $db->t('order') . " o"
            . " LEFT JOIN " . $db->t('order_status') . " os ON os.`order_status_id` = o.`order_status_id` AND os.`language_id` = " . $L
            . $w . " GROUP BY o.`order_status_id`, os.`name` ORDER BY revenue DESC");

        $top = [];

        if ($topN > 0) {
            // group by the numeric id only (cheap), take the name / model of each product afterwards
            $top = $db->rows("SELECT op.`product_id`, MAX(op.`name`) AS name, MAX(op.`model`) AS model, SUM(op.`quantity`) AS quantity, ROUND(SUM(op.`total`), 2) AS revenue FROM " . $db->t('order_product') . " op"
                . " INNER JOIN " . $db->t('order') . " o ON o.`order_id` = op.`order_id`" . $w
                . " GROUP BY op.`product_id` ORDER BY revenue DESC LIMIT " . $topN);
        }

        $cast = function (array $rows, array $intCols, array $floatCols): array {
            foreach ($rows as &$r) {
                foreach ($intCols as $k) {
                    if (isset($r[$k])) {
                        $r[$k] = (int)$r[$k];
                    }
                }

                foreach ($floatCols as $k) {
                    if (isset($r[$k])) {
                        $r[$k] = (float)$r[$k];
                    }
                }
            }

            return $rows;
        };

        return ToolResult::data([
            'period'    => ['from' => $from, 'to' => $to, 'group_by' => $groupBy],
            'currency'  => $c->currency,
            'summary'   => [
                'orders'    => (int)($summary['orders'] ?? 0),
                'revenue'   => (float)($summary['revenue'] ?? 0),
                'avg_order' => (float)($summary['avg_order'] ?? 0),
                'items'     => $items,
                'customers' => (int)($summary['customers'] ?? 0),
            ],
            'series'    => $cast($series, ['orders', 'items'], ['revenue', 'avg_order']),
            'by_status' => $cast($byStatus, ['order_status_id', 'orders'], ['revenue']),
            'top_products' => $cast($top, ['product_id', 'quantity'], ['revenue']),
        ], "Sales $from..$to: " . (int)($summary['orders'] ?? 0) . " orders, revenue " . (float)($summary['revenue'] ?? 0) . " " . $c->currency . ".");
    }
}
