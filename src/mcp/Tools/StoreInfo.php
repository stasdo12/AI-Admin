<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class StoreInfo extends Tool {
    public function name(): string {
        return 'store_info';
    }

    public function describe(): array {
        return [
            'title'       => 'Store overview',
            'description' => 'Store overview: name, URL, OpenCart/PHP versions, access mode, table prefix, languages, currencies, order statuses, stores, key counts, store map (theme, enabled modules, blogs in use). Call this first.',
            'inputSchema' => self::schema([]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $db = $c->db;
        $L = (int)$c->languageId;

        $policy = $c->policy();
        $closed = []; // parts of the store the owner closed: their counts and lists are left out, and said so
        $count = function (string $table, string $sql) use ($db, $policy, &$closed): ?int {
            if (!$policy->canRead($table)) {
                $closed[$table] = true;

                return null;
            }

            return (int)$db->value($sql, 'c');
        };
        $rows = function (string $table, string $sql) use ($db, $policy, &$closed): ?array {
            if (!$policy->canRead($table)) {
                $closed[$table] = true;

                return null;
            }

            return $db->rows($sql);
        };

        $counts = array_filter([
            'products'           => $count('product', "SELECT COUNT(*) AS c FROM " . $db->t('product')),
            'products_enabled'   => $count('product', "SELECT COUNT(*) AS c FROM " . $db->t('product') . " WHERE `status` = 1"),
            'categories'         => $count('category', "SELECT COUNT(*) AS c FROM " . $db->t('category')),
            'manufacturers'      => $count('manufacturer', "SELECT COUNT(*) AS c FROM " . $db->t('manufacturer')),
            'customers'          => $count('customer', "SELECT COUNT(*) AS c FROM " . $db->t('customer')),
            'orders'             => $count('order', "SELECT COUNT(*) AS c FROM " . $db->t('order') . " WHERE `order_status_id` > 0"),
            'orders_last_30_days'=> $count('order', "SELECT COUNT(*) AS c FROM " . $db->t('order') . " WHERE `order_status_id` > 0 AND `date_added` >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),
            'orders_missing'     => $count('order', "SELECT COUNT(*) AS c FROM " . $db->t('order') . " WHERE `order_status_id` = 0"),
        ], function ($v) { return $v !== null; });

        $statuses = $rows('order_status', "SELECT `order_status_id`, `name` FROM " . $db->t('order_status') . " WHERE `language_id` = " . $L . " ORDER BY `order_status_id`");
        $languages = $rows('language', "SELECT `language_id`, `name`, `code`, `status` FROM " . $db->t('language') . " ORDER BY `sort_order`, `language_id`");
        if ($languages && ($locked = \OcMcp\ContentEntities::lockedLanguages($c))) {
            foreach ($languages as &$l) {
                $l['ai_writes'] = !isset($locked[(int)$l['language_id']]); // false: the owner writes this language, the AI only reads it
            }

            unset($l);
        }

        $currencies = $rows('currency', "SELECT `currency_id`, `title`, `code`, `symbol_left`, `symbol_right`, `value`, `status` FROM " . $db->t('currency') . " ORDER BY `currency_id`");
        $groups = $rows('customer_group', "SELECT cg.`customer_group_id`, " . ($policy->canRead('customer_group_description') ? "cgd.`name`" : "''") . " AS name FROM " . $db->t('customer_group') . " cg"
            . ($policy->canRead('customer_group_description') ? " LEFT JOIN " . $db->t('customer_group_description') . " cgd ON cgd.`customer_group_id` = cg.`customer_group_id` AND cgd.`language_id` = " . $L : '') . " ORDER BY cg.`customer_group_id`");
        $stockStatuses = $rows('stock_status', "SELECT `stock_status_id`, `name` FROM " . $db->t('stock_status') . " WHERE `language_id` = " . $L . " ORDER BY `stock_status_id`");

        $stores = [];

        foreach ($c->stores() as $sid => $s) {
            // each store's own defaults: what an order placed there or a page shown there is in
            $stores[] = ['store_id' => $sid, 'name' => $s['name'], 'url' => $s['url'], 'language' => (string)($c->storeConfig($sid, 'config_language_catalog', '') ?: $c->storeConfig($sid, 'config_language', '')), // 4.1 keeps the shop-front language in config_language_catalog
                         'currency' => (string)$c->storeConfig($sid, 'config_currency', ''), 'order_status_id' => (int)$c->storeConfig($sid, 'config_order_status_id', 0)];
        }

        // the owner's contacts are settings: shown only while Settings is open and the keys are not hidden
        $contact = function (string $key) use ($c, $policy): ?string {
            return $policy->canRead('setting') && !in_array($key, $policy->hiddenColumns('setting'), true) ? (string)$c->config($key) : null;
        };

        $data = [
            'store' => array_filter([
                'name'      => $c->storeName,
                'url'       => $c->storeUrl,
                'owner'     => $contact('config_owner'),
                'email'     => $contact('config_email'),
                'telephone' => $contact('config_telephone'),
            ], function ($v) { return $v !== null; }),
            'platform' => [
                'opencart_version' => $c->ocVersion,
                'module_version'   => \OcMcp\Version::full(),
                'php_version'      => PHP_VERSION,
                'db_prefix'        => $db->prefix(),
                'server_time'      => date('c'),
                'timezone'         => date_default_timezone_get(),
            ],
            'access' => [
                'mode'        => $c->mode,
                'write_tools' => $c->can(Tool::LEVEL_WRITE),
                'table_access' => $c->policy()->summary(),
            ],
            'defaults' => [
                'language_id'          => $L,
                'language'             => (string)$c->config('config_language'),
                'currency'             => $c->currency,
                'customer_group_id'    => (int)$c->customerGroupId,
                'order_status_id'      => (int)$c->config('config_order_status_id'),
                'processing_status_ids'=> array_map('intval', (array)$c->config('config_processing_status', [])),
                'complete_status_ids'  => array_map('intval', (array)$c->config('config_complete_status', [])),
                'tax_enabled'          => (bool)$c->config('config_tax'),
                'maintenance_mode'     => (bool)$c->config('config_maintenance'),
                'seo_urls'             => (bool)$c->config('config_seo_url'),
            ],
            'counts'          => $counts,
            'order_statuses'  => $statuses,
            'stock_statuses'  => $stockStatuses,
            'languages'       => $languages,
            'currencies'      => $currencies,
            'customer_groups' => $groups,
            'stores'          => $stores,
        ];
        // what the store really runs (theme, enabled extensions and modifications, blogs with the signs of the one in use):
        // names and on/off only, never a setting's value, so it follows Settings and not the closed extension tables
        if ($policy->canRead('setting')) {
            try {
                $data['store_map'] = \OcMcp\StoreMap::of($c);
                $blog = \OcMcp\ContentEntities::profile($c, 'article');

                if ($blog && !empty($data['store_map']['blogs'])) {
                    $data['store_map']['blog_pages_go_to'] = $blog['label'] ?? $blog['table']; // score in blogs = signs that the storefront shows it
                }
            } catch (\Throwable $e) {
                // the map is a hint: an odd table never breaks the overview
            }
        } else {
            $closed['setting'] = true;
        }

        $data = array_filter($data, function ($v) { return $v !== null; });

        if ($closed) {
            $data['not_shown'] = 'closed for the AI by the store owner: ' . implode(', ', array_keys($closed));
        }

        return ToolResult::data($data);
    }
}
