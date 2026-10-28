<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\TablePolicy;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class ListVouchers extends Tool {
    public function name(): string {
        return 'list_vouchers';
    }

    public function tables(): array {
        return ['voucher'];
    }

    public function fieldsKey(): ?string {
        return 'voucher_id';
    }

    public function describe(): array {
        return [
            'title'       => 'Gift vouchers and their usage',
            'description' => 'List gift vouchers with amount, used amount, remaining balance, status, theme, sender/recipient and order. Voucher codes are always masked.',
            'inputSchema' => self::schema([
                'search' => self::prop('string', 'Search in recipient or sender name / e-mail.'),
                'status' => self::prop('boolean', 'Only enabled (true) / disabled (false) vouchers.'),
                'limit'  => self::prop('integer', 'Page size 1-200 (default 50).'),
                'offset' => self::prop('integer', 'Offset (default 0).'),
            ]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $db = $c->db;
        $where = ['1'];
        $search = $this->str($a, 'search');

        if ($search !== null && $search !== '') {
            $like = $db->like($search);
            $where[] = "(v.`to_name` LIKE $like OR v.`to_email` LIKE $like OR v.`from_name` LIKE $like OR v.`from_email` LIKE $like)";
        }

        $status = $this->bool($a, 'status');

        if ($status !== null) {
            $where[] = "v.`status` = " . ($status ? 1 : 0);
        }

        $limit = $this->limit($a, 50);
        $offset = $this->offset($a);
        $w = implode(' AND ', $where);
        $hasHistory = $c->hasTable('voucher_history');
        $used = $hasHistory ? "(SELECT COALESCE(SUM(-vh.`amount`), 0) FROM " . $db->t('voucher_history') . " vh WHERE vh.`voucher_id` = v.`voucher_id`)" : '0';

        $total = (int)$db->value("SELECT COUNT(*) AS c FROM " . $db->t('voucher') . " v WHERE " . $w, 'c');
        $rows = $db->rows("SELECT v.`voucher_id`, v.`order_id`, v.`code`, v.`from_name`, v.`from_email`, v.`to_name`, v.`to_email`, v.`voucher_theme_id`, v.`amount`, v.`status`, v.`date_added`, " . $used . " AS used"
            . " FROM " . $db->t('voucher') . " v WHERE " . $w . " ORDER BY v.`voucher_id` DESC LIMIT " . $offset . ", " . $limit);

        $items = [];

        foreach ($rows as $r) {
            $items[] = [
                'voucher_id' => (int)$r['voucher_id'], 'order_id' => (int)$r['order_id'], 'code' => TablePolicy::mask((string)$r['code']),
                'from_name' => $r['from_name'], 'from_email' => $r['from_email'], 'to_name' => $r['to_name'], 'to_email' => $r['to_email'],
                'theme_id' => (int)$r['voucher_theme_id'], 'amount' => (float)$r['amount'], 'used' => round((float)$r['used'], 2),
                'balance' => round((float)$r['amount'] - (float)$r['used'], 2), 'status' => (bool)$r['status'], 'date_added' => $r['date_added'],
            ];
        }

        return ToolResult::data(['total' => $total, 'limit' => $limit, 'offset' => $offset, 'currency' => $c->currency, 'items' => $items], "$total voucher(s); codes are masked.");
    }
}
