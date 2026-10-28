<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class ListCustomers extends Tool {
    public function name(): string {
        return 'list_customers';
    }

    public function tables(): array {
        return ['customer'];
    }

    public function fieldsKey(): ?string {
        return 'customer_id';
    }

    public function describe(): array {
        return [
            'title'       => 'List / search customers',
            'description' => 'Search customers by name, e-mail or phone; filter by group, status, newsletter. Includes order count and lifetime total per customer. Paginated, newest first.',
            'inputSchema' => self::schema([
                'search'            => self::prop('string', 'Search in first/last name, e-mail, telephone.'),
                'customer_group_id' => self::prop('integer', 'Customer group ID.'),
                'store_id'          => self::prop('integer', 'Only customers who registered in this store.'),
                'status'            => self::prop('boolean', 'Enabled (true) / disabled (false).'),
                'newsletter'        => self::prop('boolean', 'Subscribed to newsletter.'),
                'date_from'         => self::prop('string', 'Registered on/after (YYYY-MM-DD).'),
                'sort'              => self::prop('string', 'Sort field.', ['enum' => ['date_added', 'name', 'email', 'orders', 'total_spent']]),
                'order'             => self::prop('string', 'Sort direction.', ['enum' => ['asc', 'desc']]),
                'limit'             => self::prop('integer', 'Page size 1-200 (default 25).'),
                'offset'            => self::prop('integer', 'Offset (default 0).'),
            ]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $db = $c->db;
        $L = (int)$c->languageId;
        $where = ['1'];

        $search = $this->str($a, 'search');

        if ($search !== null && $search !== '') {
            $like = $db->like($search);
            $where[] = "(CONCAT(cu.`firstname`, ' ', cu.`lastname`) LIKE $like OR cu.`email` LIKE $like OR cu.`telephone` LIKE $like)";
        }

        $group = $this->int($a, 'customer_group_id');

        if ($group) {
            $where[] = "cu.`customer_group_id` = " . $group;
        }

        if (array_key_exists('store_id', $a) && $a['store_id'] !== null && $a['store_id'] !== '') {
            $where[] = "cu.`store_id` = " . $c->storeIds($a['store_id'], 'store_id')[0];
        }

        $status = $this->bool($a, 'status');

        if ($status !== null) {
            $where[] = "cu.`status` = " . ($status ? 1 : 0);
        }

        $newsletter = $this->bool($a, 'newsletter');

        if ($newsletter !== null) {
            $where[] = "cu.`newsletter` = " . ($newsletter ? 1 : 0);
        }

        $from = $this->date($a, 'date_from');

        if ($from) {
            $where[] = "cu.`date_added` >= " . $db->q($from . ' 00:00:00'); // no DATE(): keeps an index on date_added usable
        }

        $ordersOpen = $c->policy()->canRead('order'); // Sales is its own part of the Access tab
        $sortMap = ['date_added' => 'cu.`date_added`', 'name' => 'cu.`lastname`', 'email' => 'cu.`email`', 'orders' => 'orders_count', 'total_spent' => 'total_spent'];
        $sort = $sortMap[$this->enum($a, 'sort', array_keys($sortMap), 'date_added')];

        if (!$ordersOpen && ($sort === 'orders_count' || $sort === 'total_spent')) {
            return ToolResult::error('Sorting by orders needs the Sales part open for the AI (closed by the store owner). Sort by date_added, name or email.');
        }
        $dir = strtoupper($this->enum($a, 'order', ['asc', 'desc'], 'desc'));
        $limit = $this->limit($a);
        $offset = $this->offset($a);

        $base = " FROM " . $db->t('customer') . " cu LEFT JOIN " . $db->t('customer_group_description') . " cgd ON cgd.`customer_group_id` = cu.`customer_group_id` AND cgd.`language_id` = " . $L
            . " WHERE " . implode(' AND ', $where);

        $total = (int)$db->value("SELECT COUNT(*) AS c" . $base, 'c');

        $fields = "cu.`customer_id`, cu.`firstname`, cu.`lastname`, cu.`email`, cu.`telephone`, cu.`customer_group_id`, cgd.`name` AS customer_group, cu.`newsletter`, cu.`status`, cu.`date_added`";
        $paid = " WHERE o.`order_status_id` > 0 AND o.`customer_id` > 0";

        if ($sort === 'orders_count' || $sort === 'total_spent') {
            // sorting by order totals: aggregate the orders once (one pass), never a sub-query per customer
            $rows = $db->rows("SELECT " . $fields . ", COALESCE(ag.`c`, 0) AS orders_count, COALESCE(ag.`s`, 0) AS total_spent"
                . " FROM " . $db->t('customer') . " cu LEFT JOIN " . $db->t('customer_group_description') . " cgd ON cgd.`customer_group_id` = cu.`customer_group_id` AND cgd.`language_id` = " . $L
                . " LEFT JOIN (SELECT o.`customer_id`, COUNT(*) AS c, SUM(o.`total`) AS s FROM " . $db->t('order') . " o" . $paid . " GROUP BY o.`customer_id`) ag ON ag.`customer_id` = cu.`customer_id`"
                . " WHERE " . implode(' AND ', $where) . " ORDER BY " . $sort . " " . $dir . ", cu.`customer_id` " . $dir . " LIMIT " . $offset . ", " . $limit);
        } else {
            // one page of customers, then their order totals in one grouped query (no N+1)
            $rows = $db->rows("SELECT " . $fields . $base . " ORDER BY " . $sort . " " . $dir . ", cu.`customer_id` " . $dir . " LIMIT " . $offset . ", " . $limit);
            $ids = array_map('intval', array_column($rows, 'customer_id'));
            $agg = [];

            if ($ids && $ordersOpen) {
                foreach ($db->rows("SELECT o.`customer_id`, COUNT(*) AS c, SUM(o.`total`) AS s FROM " . $db->t('order') . " o" . $paid . " AND o.`customer_id` IN (" . implode(',', $ids) . ") GROUP BY o.`customer_id`") as $g) {
                    $agg[(int)$g['customer_id']] = $g;
                }
            }

            foreach ($rows as $i => $r) {
                $g = $agg[(int)$r['customer_id']] ?? null;
                $rows[$i]['orders_count'] = $g ? $g['c'] : 0;
                $rows[$i]['total_spent'] = $g ? $g['s'] : 0;
            }
        }

        $items = [];

        foreach ($rows as $r) {
            $items[] = [
                'customer_id'       => (int)$r['customer_id'],
                'name'              => trim($r['firstname'] . ' ' . $r['lastname']),
                'email'             => (string)$r['email'],
                'telephone'         => (string)$r['telephone'],
                'customer_group_id' => (int)$r['customer_group_id'],
                'customer_group'    => (string)$r['customer_group'],
                'newsletter'        => (bool)$r['newsletter'],
                'status'            => (bool)$r['status'],
                'orders_count'      => (int)$r['orders_count'],
                'total_spent'       => round((float)$r['total_spent'], 2),
                'date_added'        => (string)$r['date_added'],
            ];
        }

        $hidden = $c->policy()->hiddenColumns('customer');

        foreach ($items as &$it) {
            if (array_intersect(['firstname', 'lastname'], $hidden)) {
                unset($it['name']); // built from hidden fields
            }

            if (!$ordersOpen) {
                unset($it['orders_count'], $it['total_spent']);
            }
        }
        unset($it);

        $out = ['total' => $total, 'limit' => $limit, 'offset' => $offset, 'currency' => $c->currency, 'items' => $items];

        if (!$ordersOpen) {
            $out['not_shown'] = 'closed for the AI by the store owner: orders (orders_count, total_spent)';
        }

        return ToolResult::data($out, "Found $total customer(s); showing " . count($items) . ".");
    }
}
