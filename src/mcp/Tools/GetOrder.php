<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class GetOrder extends Tool {
    public function name(): string {
        return 'get_order';
    }

    public function tables(): array {
        return ['order', 'order_product', 'order_total', 'order_history'];
    }

    public function fieldsKey(): ?string {
        return 'order_id';
    }

    public function describe(): array {
        return [
            'title'       => 'Get order details',
            'description' => 'Complete order: customer and addresses, payment/shipping methods, products with options, totals breakdown, comment and status history.',
            'inputSchema' => self::schema([
                'order_id' => ['type' => 'integer'],
            ], ['order_id']),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $this->need($a, 'order_id');
        $id = (int)$this->int($a, 'order_id');
        $db = $c->db;
        $L = (int)$c->languageId;

        $o = $db->row("SELECT o.*, os.`name` AS status_name FROM " . $db->t('order') . " o LEFT JOIN " . $db->t('order_status') . " os ON os.`order_status_id` = o.`order_status_id` AND os.`language_id` = " . $L . " WHERE o.`order_id` = " . $id);

        if ($o === null) {
            return ToolResult::error("Order $id not found.");
        }

        $products = [];

        $optionsByProduct = []; // all options of the order in one query (no query per product line)

        foreach ($db->rows("SELECT `order_product_id`, `name`, `value`, `type` FROM " . $db->t('order_option') . " WHERE `order_id` = " . $id . " ORDER BY `order_option_id`") as $o) {
            $optionsByProduct[(int)$o['order_product_id']][] = ['name' => $o['name'], 'value' => $o['value'], 'type' => $o['type']];
        }

        foreach ($db->rows("SELECT * FROM " . $db->t('order_product') . " WHERE `order_id` = " . $id . " ORDER BY `order_product_id`") as $p) {
            $options = $optionsByProduct[(int)$p['order_product_id']] ?? [];
            $products[] = [
                'order_product_id' => (int)$p['order_product_id'],
                'product_id'       => (int)$p['product_id'],
                'name'             => (string)$p['name'],
                'model'            => (string)$p['model'],
                'quantity'         => (int)$p['quantity'],
                'price'            => (float)$p['price'],
                'total'            => (float)$p['total'],
                'tax'              => (float)$p['tax'],
                'options'          => $options,
            ];
        }

        $totals = [];

        foreach ($db->rows("SELECT `code`, `title`, `value`, `sort_order` FROM " . $db->t('order_total') . " WHERE `order_id` = " . $id . " ORDER BY `sort_order`") as $t) {
            $totals[] = ['code' => $t['code'], 'title' => $t['title'], 'value' => (float)$t['value']];
        }

        $history = [];

        foreach ($db->rows("SELECT oh.`date_added`, oh.`order_status_id`, os.`name` AS status, oh.`comment`, oh.`notify` FROM " . $db->t('order_history') . " oh LEFT JOIN " . $db->t('order_status') . " os ON os.`order_status_id` = oh.`order_status_id` AND os.`language_id` = " . $L . " WHERE oh.`order_id` = " . $id . " ORDER BY oh.`date_added`, oh.`order_history_id`") as $h) {
            $history[] = ['date_added' => $h['date_added'], 'order_status_id' => (int)$h['order_status_id'], 'status' => (string)$h['status'], 'comment' => (string)$h['comment'], 'notify' => (bool)$h['notify']];
        }

        $address = function (string $prefix) use ($o): array {
            $fields = ['firstname', 'lastname', 'company', 'address_1', 'address_2', 'city', 'postcode', 'zone', 'country', 'method', 'code'];
            $out = [];

            foreach ($fields as $f) {
                if (array_key_exists($prefix . $f, $o)) {
                    $out[$f] = (string)$o[$prefix . $f];
                }
            }

            if (isset($out['method'])) {
                $out['method'] = self::methodName($out['method']);
            }

            return $out;
        };

        $data = [
            'order_id'        => (int)$o['order_id'],
            'invoice'         => in_array('invoice_no', $c->policy()->hiddenColumns('order'), true) ? null : ($o['invoice_no'] ? $o['invoice_prefix'] . $o['invoice_no'] : null),
            'store'           => ['store_id' => (int)$o['store_id'], 'name' => (string)$o['store_name'], 'url' => (string)$o['store_url']],
            'order_status_id' => (int)$o['order_status_id'],
            'status'          => (string)($o['status_name'] ?? ''),
            'customer'        => [
                'customer_id'       => (int)$o['customer_id'],
                'customer_group_id' => (int)$o['customer_group_id'],
                'firstname'         => (string)$o['firstname'],
                'lastname'          => (string)$o['lastname'],
                'email'             => (string)$o['email'],
                'telephone'         => (string)$o['telephone'],
            ],
            'payment'         => $address('payment_'),
            'shipping'        => $address('shipping_'),
            'comment'         => (string)$o['comment'],
            'products'        => $products,
            'totals'          => $totals,
            'total'           => (float)$o['total'],
            'currency_code'   => (string)$o['currency_code'],
            'currency_value'  => (float)$o['currency_value'],
            'total_in_order_currency' => round((float)$o['total'] * (float)$o['currency_value'], 2),
            'ip'              => (string)$o['ip'],
            'user_agent'      => (string)$o['user_agent'],
            'language_id'     => (int)$o['language_id'],
            'date_added'      => (string)$o['date_added'],
            'date_modified'   => (string)$o['date_modified'],
            'history'         => $history,
        ];

        return ToolResult::data($data);
    }
}
