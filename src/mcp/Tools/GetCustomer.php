<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class GetCustomer extends Tool {
    public function name(): string {
        return 'get_customer';
    }

    public function tables(): array {
        return ['customer', 'address'];
    }

    public function fieldsKey(): ?string {
        return 'customer_id';
    }

    public function describe(): array {
        return [
            'title'       => 'Get customer details',
            'description' => 'Customer profile with addresses, group, status and the 20 most recent orders. Look up by customer_id or e-mail.',
            'inputSchema' => self::schema([
                'customer_id' => ['type' => 'integer'],
                'email'       => self::prop('string', 'Customer e-mail (if customer_id is unknown).'),
            ]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $db = $c->db;
        $L = (int)$c->languageId;
        $id = $this->int($a, 'customer_id');
        $email = $this->str($a, 'email');

        if (!$id && ($email === null || $email === '')) {
            return ToolResult::error('Provide customer_id or email.');
        }

        $where = $id ? "cu.`customer_id` = " . $id : "cu.`email` = " . $db->q(trim($email)) /* utf8mb4_general_ci compares case-insensitively; no LOWER() so the e-mail index is used */;

        $policy = $c->policy();
        $closed = []; // parts of the store the owner closed: left out of the answer, and said so
        $groupJoin = $policy->canRead('customer_group_description') ? " LEFT JOIN " . $db->t('customer_group_description') . " cgd ON cgd.`customer_group_id` = cu.`customer_group_id` AND cgd.`language_id` = " . $L : '';
        $cu = $db->row("SELECT cu.*, " . ($groupJoin ? "cgd.`name`" : "''") . " AS customer_group FROM " . $db->t('customer') . " cu" . $groupJoin . " WHERE " . $where . " LIMIT 1");

        if (!$groupJoin) {
            $closed[] = 'customer_group';
        }

        if ($cu === null) {
            return ToolResult::error('Customer not found.');
        }

        $id = (int)$cu['customer_id'];

        // OC 4.1 keeps zone / country names per language in *_description tables; older versions in zone / country
        $geo = $policy->canRead('zone') && $policy->canRead('country');

        if (!$geo) {
            $closed[] = 'zone / country names';
        }

        $zoneJoin = !$geo ? '' : ($c->hasTable('zone_description')
            ? " LEFT JOIN " . $db->t('zone_description') . " z ON z.`zone_id` = ad.`zone_id` AND z.`language_id` = " . $L
            : " LEFT JOIN " . $db->t('zone') . " z ON z.`zone_id` = ad.`zone_id`");
        $countryJoin = !$geo ? '' : ($c->hasTable('country_description')
            ? " LEFT JOIN " . $db->t('country_description') . " co ON co.`country_id` = ad.`country_id` AND co.`language_id` = " . $L
            : " LEFT JOIN " . $db->t('country') . " co ON co.`country_id` = ad.`country_id`");
        $addresses = $db->rows("SELECT ad.`address_id`, ad.`firstname`, ad.`lastname`, ad.`company`, ad.`address_1`, ad.`address_2`, ad.`city`, ad.`postcode`, " . ($geo ? "z.`name` AS zone, co.`name` AS country" : "ad.`zone_id`, ad.`country_id`")
            . " FROM " . $db->t('address') . " ad" . $zoneJoin . $countryJoin
            . " WHERE ad.`customer_id` = " . $id);

        $orders = [];
        $stats = null;

        if ($policy->canRead('order')) { // Sales is its own part of the Access tab
            $statusJoin = $policy->canRead('order_status') ? " LEFT JOIN " . $db->t('order_status') . " os ON os.`order_status_id` = o.`order_status_id` AND os.`language_id` = " . $L : '';
            $orders = $db->rows("SELECT o.`order_id`, o.`total`, o.`currency_code`, o.`order_status_id`, " . ($statusJoin ? "os.`name`" : "''") . " AS status, o.`date_added` FROM " . $db->t('order') . " o" . $statusJoin
                . " WHERE o.`customer_id` = " . $id . " AND o.`order_status_id` > 0 ORDER BY o.`date_added` DESC LIMIT 20");
            $stats = $db->row("SELECT COUNT(*) AS orders_count, COALESCE(SUM(`total`), 0) AS total_spent, MAX(`date_added`) AS last_order FROM " . $db->t('order') . " WHERE `customer_id` = " . $id . " AND `order_status_id` > 0");
        } else {
            $closed[] = 'orders';
        }

        $data = [
            'customer_id'       => $id,
            'firstname'         => (string)$cu['firstname'],
            'lastname'          => (string)$cu['lastname'],
            'email'             => (string)$cu['email'],
            'telephone'         => (string)$cu['telephone'],
            'customer_group_id' => (int)$cu['customer_group_id'],
            'customer_group'    => (string)$cu['customer_group'],
            'newsletter'        => (bool)$cu['newsletter'],
            'status'            => (bool)$cu['status'],
            'safe'              => (bool)($cu['safe'] ?? 0),
            'store_id'          => (int)$cu['store_id'],
            'language_id'       => (int)($cu['language_id'] ?? 0),
            'date_added'        => (string)$cu['date_added'],
            'addresses'         => $addresses,
        ];

        if ($stats !== null) {
            $data['stats'] = [
                'orders_count' => (int)($stats['orders_count'] ?? 0),
                'total_spent'  => round((float)($stats['total_spent'] ?? 0), 2),
                'last_order'   => $stats['last_order'] ?? null,
                'currency'     => $c->currency,
            ];
            $data['recent_orders'] = array_map(function ($o) {
                return ['order_id' => (int)$o['order_id'], 'total' => (float)$o['total'], 'currency_code' => $o['currency_code'], 'order_status_id' => (int)$o['order_status_id'], 'status' => (string)$o['status'], 'date_added' => $o['date_added']];
            }, $orders);
        }

        if ($closed) {
            $data['not_shown'] = 'closed for the AI by the store owner: ' . implode(', ', $closed);
        }

        return ToolResult::data($data);
    }
}
