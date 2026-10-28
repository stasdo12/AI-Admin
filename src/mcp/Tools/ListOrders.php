<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class ListOrders extends Tool {
    public function name(): string {
        return 'list_orders';
    }

    public function tables(): array {
        return ['order'];
    }

    public function fieldsKey(): ?string {
        return 'order_id';
    }

    public function describe(): array {
        return [
            'title'       => 'List orders',
            'description' => 'List orders with customer, total, currency, status and dates. Filter by status, date range, customer text (name / e-mail / phone), order ID, minimum total. Orders with status 0 ("missing", abandoned checkouts) are excluded unless include_missing=true. Paginated, newest first by default.',
            'inputSchema' => self::schema([
                'order_id'        => self::prop('integer', 'Exact order ID.'),
                'order_status_id' => self::prop('integer', 'Filter by order status ID (see store_info).'),
                'status'          => self::prop('string', 'Filter by status name (case-insensitive substring), e.g. "pending".'),
                'customer'        => self::prop('string', 'Search in customer first/last name, e-mail and telephone.'),
                'customer_id'     => self::prop('integer', 'Only orders of this customer ID.'),
                'date_from'       => self::prop('string', 'Orders added on/after this date (YYYY-MM-DD).'),
                'date_to'         => self::prop('string', 'Orders added on/before this date (YYYY-MM-DD).'),
                'min_total'       => self::prop('number', 'Minimum order total.'),
                'store_id'        => self::prop('integer', 'Store ID (multi-store).'),
                'include_missing' => self::prop('boolean', 'Include status-0 (missing) orders (default false).'),
                'sort'            => self::prop('string', 'Sort field.', ['enum' => ['date_added', 'date_modified', 'total', 'order_id']]),
                'order'           => self::prop('string', 'Sort direction.', ['enum' => ['asc', 'desc']]),
                'limit'           => self::prop('integer', 'Page size, 1-200 (default 25).'),
                'offset'          => self::prop('integer', 'Offset (default 0).'),
            ]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $db = $c->db;
        $L = (int)$c->languageId;
        $where = [];

        $orderId = $this->int($a, 'order_id');

        if ($orderId) {
            $where[] = "o.`order_id` = " . $orderId;
        }

        if (!$this->bool($a, 'include_missing', false)) {
            $where[] = "o.`order_status_id` > 0";
        }

        $statusId = $this->int($a, 'order_status_id');

        if ($statusId !== null) {
            $where[] = "o.`order_status_id` = " . $statusId;
        }

        $statusName = $this->str($a, 'status');

        if ($statusName !== null && $statusName !== '') {
            $where[] = "os.`name` LIKE " . $db->like($statusName);
        }

        $customer = $this->str($a, 'customer');

        if ($customer !== null && $customer !== '') {
            $like = $db->like($customer);
            $where[] = "(CONCAT(o.`firstname`, ' ', o.`lastname`) LIKE $like OR o.`email` LIKE $like OR o.`telephone` LIKE $like)";
        }

        $customerId = $this->int($a, 'customer_id');

        if ($customerId) {
            $where[] = "o.`customer_id` = " . $customerId;
        }

        $from = $this->date($a, 'date_from');
        $to = $this->date($a, 'date_to');

        if ($from) {
            $where[] = "o.`date_added` >= " . $db->q($from . ' 00:00:00'); // range, not DATE(): an index on date_added stays usable
        }

        if ($to) {
            $where[] = "o.`date_added` <= " . $db->q($to . ' 23:59:59');
        }

        $minTotal = $this->num($a, 'min_total');

        if ($minTotal !== null) {
            $where[] = "o.`total` >= " . (float)$minTotal;
        }

        $storeId = $this->int($a, 'store_id');

        if ($storeId !== null) {
            $where[] = "o.`store_id` = " . $storeId;
        }

        $sortMap = ['date_added' => 'o.`date_added`', 'date_modified' => 'o.`date_modified`', 'total' => 'o.`total`', 'order_id' => 'o.`order_id`'];
        $sort = $sortMap[$this->enum($a, 'sort', array_keys($sortMap), 'date_added')];
        $dir = strtoupper($this->enum($a, 'order', ['asc', 'desc'], 'desc'));
        $limit = $this->limit($a);
        $offset = $this->offset($a);

        $base = " FROM " . $db->t('order') . " o LEFT JOIN " . $db->t('order_status') . " os ON os.`order_status_id` = o.`order_status_id` AND os.`language_id` = " . $L
            . ($where ? " WHERE " . implode(' AND ', $where) : '');

        $total = (int)$db->value("SELECT COUNT(*) AS c" . $base, 'c');
        $sum = (float)$db->value("SELECT COALESCE(SUM(o.`total`), 0) AS s" . $base, 's');

        $rows = $db->rows("SELECT o.`order_id`, o.`invoice_prefix`, o.`invoice_no`, o.`store_id`, o.`customer_id`, o.`firstname`, o.`lastname`, o.`email`, o.`telephone`,"
            . " o.`total`, o.`currency_code`, o.`currency_value`, o.`order_status_id`, os.`name` AS status, o.`payment_method`, o.`shipping_method`, o.`date_added`, o.`date_modified`"
            . $base . " ORDER BY " . $sort . " " . $dir . ", o.`order_id` " . $dir . " LIMIT " . $offset . ", " . $limit);

        $items = [];

        $hidden = $c->policy()->hiddenColumns('order');

        foreach ($rows as $r) {
            $items[] = [
                'order_id'        => (int)$r['order_id'],
                'invoice'         => in_array('invoice_no', $hidden, true) ? null : ($r['invoice_no'] ? $r['invoice_prefix'] . $r['invoice_no'] : null),
                'store_id'        => (int)$r['store_id'],
                'customer_id'     => (int)$r['customer_id'],
                'customer'        => array_intersect(['firstname', 'lastname'], $hidden) ? null : trim($r['firstname'] . ' ' . $r['lastname']),
                'email'           => (string)$r['email'],
                'telephone'       => (string)$r['telephone'],
                'total'           => (float)$r['total'],
                'total_in_order_currency' => round((float)$r['total'] * (float)$r['currency_value'], 2),
                'currency_code'   => (string)$r['currency_code'],
                'order_status_id' => (int)$r['order_status_id'],
                'status'          => (string)($r['status'] ?? ''),
                'payment_method'  => self::methodName($r['payment_method']),
                'shipping_method' => self::methodName($r['shipping_method']),
                'date_added'      => (string)$r['date_added'],
                'date_modified'   => (string)$r['date_modified'],
            ];
        }

        return ToolResult::data([
            'total'          => $total,
            'sum_total'      => round($sum, 2),
            'currency'       => $c->currency,
            'limit'          => $limit,
            'offset'         => $offset,
            'items'          => $items,
        ], "Found $total order(s), sum " . round($sum, 2) . " " . $c->currency . "; showing " . count($items) . ".");
    }
}
