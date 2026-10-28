<?php
namespace OcMcp;

/**
 * The store as the owner sees it: admin menu section -> subsection -> database tables -> fields.
 *
 * Access is still enforced per table and column by TablePolicy; this map only groups tables the way the OpenCart admin
 * menu does, so the owner chooses "Catalog > Products: read + edit" instead of thinking about oc_product_to_category.
 * Field labels come from the store's own admin language files (catalog/product.php -> entry_price ...).
 * Every stock table of OpenCart 3.0.x and 4.0/4.1 belongs to exactly one subsection; anything else is "other".
 */
final class AccessMap {
    /** section => [locked => bool, subs => [key => [forms => string[], lang => string[], tables => string[]]]]; every part stands on its own */
    const SECTIONS = [
        'catalog' => ['locked' => false, 'subs' => [
            // ocStore: the "Featured" tab of a category and of a manufacturer keeps its products and articles in tables of its own (_wb, _mn)
            'categories'    => ['forms' => ['catalog/category_form'], 'lang' => ['catalog/category'], 'tables' => ['category', 'category_description', 'category_path', 'category_to_store', 'category_to_layout', 'category_filter', 'product_related_wb', 'article_related_wb'], 'form_names' => ['product_related' => 'product_related_wb', 'article_related' => 'article_related_wb']],
            'products'      => ['forms' => ['catalog/product_form'], 'lang' => ['catalog/product'], 'tables' => ['product', 'product_description', 'product_to_category', 'product_to_store', 'product_to_layout', 'product_image', 'product_attribute', 'product_option', 'product_option_value', 'product_discount', 'product_special', 'product_reward', 'product_related', 'product_related_article', 'product_filter', 'product_code', 'product_viewed', 'product_report', 'product_recurring', 'product_subscription', 'product_to_download', 'identifier']],
            'plans'         => ['forms' => ['catalog/recurring_form', 'catalog/subscription_plan_form'], 'lang' => ['catalog/recurring', 'catalog/subscription_plan'], 'tables' => ['recurring', 'recurring_description', 'subscription_plan', 'subscription_plan_description']],
            'filters'       => ['forms' => ['catalog/filter_form', 'catalog/filter_group_form'], 'lang' => ['catalog/filter', 'catalog/filter_group'], 'tables' => ['filter', 'filter_description', 'filter_group', 'filter_group_description']],
            'attributes'    => ['forms' => ['catalog/attribute_form', 'catalog/attribute_group_form'], 'lang' => ['catalog/attribute', 'catalog/attribute_group'], 'tables' => ['attribute', 'attribute_description', 'attribute_group', 'attribute_group_description']],
            'options'       => ['forms' => ['catalog/option_form'], 'lang' => ['catalog/option'], 'tables' => ['option', 'option_description', 'option_value', 'option_value_description']],
            'manufacturers' => ['forms' => ['catalog/manufacturer_form'], 'lang' => ['catalog/manufacturer'], 'tables' => ['manufacturer', 'manufacturer_description', 'manufacturer_to_store', 'manufacturer_to_layout', 'product_related_mn', 'article_related_mn'], 'form_names' => ['product_related' => 'product_related_mn', 'article_related' => 'article_related_mn']],
            'downloads'     => ['forms' => ['catalog/download_form'], 'lang' => ['catalog/download'], 'tables' => ['download', 'download_description', 'download_report']],
            'reviews'       => ['forms' => ['catalog/review_form'], 'lang' => ['catalog/review'], 'tables' => ['review']],
            'information'   => ['forms' => ['catalog/information_form'], 'lang' => ['catalog/information'], 'tables' => ['information', 'information_description', 'information_to_store', 'information_to_layout']],
            'seo'           => ['forms' => ['design/seo_url_form'], 'lang' => ['design/seo_url'], 'tables' => ['seo_url', 'url_alias']],
        ]],
        'cms' => ['locked' => false, 'subs' => [
            'blog' => ['forms' => ['cms/article_form', 'cms/topic_form'], 'lang' => ['cms/article', 'cms/topic', 'cms/comment'], 'tables' => ['article', 'article_description', 'article_to_store', 'article_to_layout', 'article_comment', 'article_rating', 'topic', 'topic_description', 'topic_to_store', 'topic_to_layout']],
        ]],
        'sales' => ['locked' => false, 'subs' => [
            'orders'         => ['layout' => 'order', 'lang' => ['sale/order'], 'tables' => ['order', 'order_product', 'order_option', 'order_total', 'order_history', 'order_shipment', 'shipping_courier']],
            'subscriptions'  => ['lang' => ['sale/recurring', 'sale/subscription'], 'tables' => ['order_recurring', 'order_recurring_transaction', 'order_subscription', 'subscription', 'subscription_history', 'subscription_log', 'subscription_option', 'subscription_product', 'subscription_transaction']],
            'returns'        => ['forms' => ['sale/return_form', 'sale/returns_form'], 'lang' => ['sale/return', 'sale/returns'], 'tables' => ['return', 'return_history']],
            'vouchers'       => ['forms' => ['sale/voucher_form', 'sale/voucher_theme_form'], 'lang' => ['sale/voucher', 'sale/voucher_theme'], 'tables' => ['voucher', 'voucher_history', 'order_voucher', 'voucher_theme', 'voucher_theme_description']],
            'carts'          => ['menu' => false, 'lang' => [], 'tables' => ['cart']],
        ]],
        'customers' => ['locked' => false, 'subs' => [
            // affiliates are customers with a commission: in 3.x their fields are a tab of the customer form, in 4.x a form of their own
            'customers'     => ['forms' => ['customer/customer_form', 'customer/address_form', 'marketing/affiliate_form', 'customer/customer_affiliate_form'], 'lang' => ['customer/customer', 'marketing/affiliate'],
                                'form_first' => ['customer_affiliate'], // the plain company / website inputs of the 3.x affiliate tab are the affiliate's, not an address's
                                'tables' => ['customer', 'address', 'customer_activity', 'customer_transaction', 'customer_reward', 'customer_ip', 'customer_history', 'customer_online', 'customer_wishlist', 'customer_search', 'customer_login', 'customer_token', 'customer_authorize',
                                             'customer_affiliate', 'customer_affiliate_report', 'affiliate', 'affiliate_activity', 'affiliate_transaction', 'affiliate_login', 'affiliate_report']],
            'groups'        => ['forms' => ['customer/customer_group_form'], 'lang' => ['customer/customer_group'], 'tables' => ['customer_group', 'customer_group_description']],
            'approvals'     => ['lang' => ['customer/customer_approval'], 'tables' => ['customer_approval']],
            'custom_fields' => ['forms' => ['customer/custom_field_form'], 'lang' => ['customer/custom_field'], 'tables' => ['custom_field', 'custom_field_customer_group', 'custom_field_description', 'custom_field_value', 'custom_field_value_description']],
            'gdpr'          => ['lang' => ['customer/gdpr'], 'tables' => ['gdpr']],
        ]],
        'marketing' => ['locked' => false, 'subs' => [
            'marketing' => ['forms' => ['marketing/marketing_form'], 'lang' => ['marketing/marketing'], 'tables' => ['marketing', 'marketing_report']],
            'coupons'   => ['forms' => ['marketing/coupon_form'], 'lang' => ['marketing/coupon'], 'tables' => ['coupon', 'coupon_product', 'coupon_category', 'coupon_history']],
            'mail'      => ['lang' => ['marketing/contact'], 'tables' => ['mcp_mail']],
        ]],
        'system' => ['locked' => false, 'subs' => [
            // the store's own parameters and the list of stores of a multistore are one part: Details shows the stores as a section of Settings
            'settings'     => ['forms' => ['setting/setting'], 'lang' => ['setting/setting', 'setting/store'], 'tables' => ['setting', 'store']],
            'users'        => ['lang' => ['user/user', 'user/user_permission'], 'tables' => ['user', 'user_group', 'user_login', 'user_authorize', 'user_token']],
            'api'          => ['lang' => ['user/api'], 'tables' => ['api', 'api_ip', 'api_session', 'api_history']],
            'service'      => ['menu' => false, 'lang' => [], 'tables' => ['session', 'upload', 'antispam', 'googleshopping_product', 'mcp_log', 'mcp_log_archive', 'mcp_log_anchor', 'mcp_log_archive_set', 'mcp_change', 'mcp_change_batch', 'mcp_rate', 'mcp_oauth_client', 'mcp_oauth_code', 'mcp_oauth_token', 'mcp_idem', 'mcp_image', 'mcp_image_trash', 'mcp_upload', 'mcp_prompt', 'mcp_prompt_section']],
        ]],
        // System › Localisation of the admin menu: one part like Settings, every list a section of its Details named after its own form
        'localisation' => ['locked' => false, 'subs' => [
            'localisation' => ['form_heading' => true,
                'forms'  => ['localisation/location_form', 'localisation/language_form', 'localisation/currency_form', 'localisation/stock_status_form', 'localisation/order_status_form', 'localisation/subscription_status_form',
                             'localisation/return_status_form', 'localisation/return_action_form', 'localisation/return_reason_form', 'localisation/country_form', 'localisation/zone_form', 'localisation/geo_zone_form',
                             'localisation/tax_class_form', 'localisation/tax_rate_form', 'localisation/length_class_form', 'localisation/weight_class_form', 'localisation/address_format_form'],
                'lang'   => ['localisation/location', 'localisation/language', 'localisation/currency', 'localisation/stock_status', 'localisation/order_status', 'localisation/subscription_status', 'localisation/return_status',
                             'localisation/return_action', 'localisation/return_reason', 'localisation/country', 'localisation/zone', 'localisation/geo_zone', 'localisation/tax_class', 'localisation/tax_rate',
                             'localisation/length_class', 'localisation/weight_class', 'localisation/address_format'],
                'tables' => ['location', 'language', 'currency', 'stock_status', 'order_status', 'subscription_status', 'return_status', 'return_action', 'return_reason', 'country', 'country_description', 'zone', 'zone_description',
                             'geo_zone', 'zone_to_geo_zone', 'tax_class', 'tax_rule', 'tax_rate', 'tax_rate_to_customer_group', 'length_class', 'length_class_description', 'weight_class', 'weight_class_description', 'address_format']],
        ]],
        'reports' => ['locked' => false, 'subs' => [
            'statistics' => ['lang' => ['report/statistics'], 'tables' => ['statistics']],
        ]],
        'design' => ['locked' => true, 'subs' => [
            'layouts'     => ['lang' => ['design/layout'], 'tables' => ['layout', 'layout_module', 'layout_route']],
            'theme'       => ['lang' => ['design/theme'], 'tables' => ['theme']],
            'translation' => ['lang' => ['design/translation'], 'tables' => ['translation']],
            'banners'     => ['lang' => ['design/banner'], 'tables' => ['banner', 'banner_image']],
        ]],
        'extensions' => ['locked' => true, 'subs' => [
            'extensions' => ['lang' => ['marketplace/extension', 'marketplace/modification'], 'tables' => ['extension', 'extension_install', 'extension_path', 'modification', 'module', 'event', 'startup', 'cron', 'notification']],
        ]],
    ];

    /** Forms without tabs of their own: the group title, a language key of the section. */
    const FORM_TITLES = ['customer/address_form' => 'tab_address'];

    /** Forms whose plain inputs (firstname, company, status) belong to another table than the section's first one. */
    const FORM_MAIN = ['customer/address_form' => 'address', 'marketing/affiliate_form' => 'customer_affiliate', 'customer/customer_affiliate_form' => 'customer_affiliate',
                       'localisation/location_form' => 'location', 'localisation/language_form' => 'language', 'localisation/currency_form' => 'currency', 'localisation/stock_status_form' => 'stock_status',
                       'localisation/order_status_form' => 'order_status', 'localisation/subscription_status_form' => 'subscription_status', 'localisation/return_status_form' => 'return_status',
                       'localisation/return_action_form' => 'return_action', 'localisation/return_reason_form' => 'return_reason', 'localisation/country_form' => 'country', 'localisation/zone_form' => 'zone',
                       'localisation/geo_zone_form' => 'geo_zone', 'localisation/tax_class_form' => 'tax_class', 'localisation/tax_rate_form' => 'tax_rate', 'localisation/length_class_form' => 'length_class',
                       'localisation/weight_class_form' => 'weight_class', 'localisation/address_format_form' => 'address_format'];


    /**
     * Orders have no edit form in OpenCart 4 and a step-by-step one in 3: the fields grouped like the 3.x order form
     * (1. Customer Details, 2. Products, 3. Payment Details, 4. Shipping Details, 5. Totals), plus Order Details and History.
     */
    public static function orderGroups(array $present, array $columns, array $strings, callable $t): array {
        $keep = '/^(customer_group|order_status|store|language)_id$/';
        // what OpenCart fills in itself and never shows as an order field: kept out of the list (the AI still reads them with the order)
        $inner = ['language_id', 'store_url', 'language_code', 'forwarded_ip', 'accept_language', 'currency_value', 'payment_address_format', 'shipping_address_format', 'payment_code', 'shipping_code', 'extension', 'code', 'type'];
        $order = ['customer' => [], 'product' => [], 'payment' => [], 'shipping' => [], 'total' => [], 'detail' => [], 'history' => []];
        $where = function (string $tb, string $c): string {
            if ($tb === 'order_history') {
                return 'history';
            }

            if (in_array($tb, ['order_product', 'order_option', 'order_subscription'], true)) {
                return 'product';
            }

            if ($tb === 'order_total') {
                return 'total';
            }

            if ($tb === 'order_shipment') {
                return 'shipping';
            }

            if (preg_match('/^payment_/', $c)) {
                return 'payment';
            }

            if (preg_match('/^shipping_/', $c)) {
                return 'shipping';
            }

            if (in_array($c, ['customer_group_id', 'firstname', 'lastname', 'email', 'telephone', 'fax', 'custom_field'], true)) {
                return 'customer';
            }

            if (in_array($c, ['total', 'order_status_id', 'comment', 'commission', 'currency_id', 'currency_code', 'currency_value', 'tracking'], true)) {
                return 'total';
            }

            return 'detail';
        };

        foreach ($present as $tb) {
            if (strpos($tb, 'order') !== 0) {
                continue;
            }

            foreach ($columns[$tb] ?? [] as $c) {
                $col = $c[0];
                $technical = $c[2] === 'PRI' || $col === 'order_id' || (substr($col, -3) === '_id' && !preg_match($keep, $col)) || $col === 'sort_order' || in_array($col, $inner, true);
                // under "Payment Details" payment_firstname is just "First Name", as on the order page
                $plain = preg_replace('/^(payment|shipping)_(?!method$)/', '', $col);
                $label = $plain === 'zone' ? self::label('zone_id', $strings) : self::label($plain, $strings);

                if ($tb === 'order_option' || $tb === 'order_total') {
                    $own = (string)$t('fld_' . $tb . '_' . $col);
                    $label = $own !== '' ? $own : $label; // "Option", "Option Value" next to the product's own Name
                }

                $order[$where($tb, $col)][] = ['table' => $tb, 'column' => $col, 'label' => $label, 'technical' => $technical];
            }
        }

        $groups = [];

        foreach ($order as $key => $fields) {
            if (array_filter($fields, function ($f) { return !$f['technical']; })) {
                $groups[] = ['title' => (string)$t('grp_order_' . $key), 'fields' => $fields];
            }
        }

        return $groups;
    }

    /** Columns holding personal data (flagged "personal" in the field picker). */
    const PERSONAL = ['firstname', 'lastname', 'email', 'telephone', 'fax', 'company', 'address_1', 'address_2', 'city', 'postcode', 'ip', 'forwarded_ip', 'user_agent', 'accept_language', 'custom_field', 'author', 'to_name', 'to_email', 'from_name', 'from_email', 'customer', 'username'];

    /** Columns holding money or balances (flagged "money"). */
    const MONEY = ['price', 'total', 'amount', 'value', 'points', 'commission', 'tax', 'discount', 'balance', 'currency_value', 'reward'];

    /** Same names, no money: an option's value is text, an affiliate's tax is a tax ID. */
    const NOT_MONEY = ['order_option.value', 'customer_affiliate.tax', 'product_option_value.subtract'];

    /** subsection key ("catalog.products") of every known table */
    public static function tableIndex(): array {
        static $index = null;

        if ($index === null) {
            $index = [];

            foreach (self::SECTIONS as $section => $def) {
                foreach ($def['subs'] as $sub => $s) {
                    foreach ($s['tables'] as $t) {
                        $index[$t] = $section . '.' . $sub;
                    }
                }
            }
        }

        return $index;
    }

    /** Values OpenCart computes itself (ratings, view counters, variant bookkeeping): shown nowhere as fields to grant. */
    const COMPUTED = ['product' => ['rating', 'viewed', 'variant', 'override', 'master_id'], 'product_viewed' => ['viewed'], 'download' => ['filename']];

    public static function isComputed(string $table, string $column): bool {
        return in_array($column, self::COMPUTED[$table] ?? [], true);
    }

    public static function isKnown(string $table): bool {
        return isset(self::tableIndex()[$table]);
    }

    public static function subsectionOf(string $table): string {
        return self::tableIndex()[$table] ?? 'other.other';
    }

    /** Read an OpenCart language file in isolation (does not touch the registry's language object). */
    public static function languageFile(string $root, string $dir, string $file): array {
        foreach ([$dir, 'en-gb'] as $d) {
            $path = rtrim($root, '/') . '/' . $d . '/' . $file . '.php';

            if (is_file($path)) {
                $read = function ($path) {
                    $_ = [];
                    include $path;

                    return $_;
                };

                return $read($path);
            }
        }

        return [];
    }

    /** Human label for a column: entry_* / column_* / text_* from the subsection's admin language files, else "Date modified". */
    /** Strings every admin page has (tab_general, tab_data, button_* ...): en-gb/en-gb.php in OpenCart 3, default.php in 4. */
    public static function baseStrings(string $root, string $dir): array {
        $out = self::languageFile($root, $dir, $dir);

        return $out ?: self::languageFile($root, $dir, 'default');
    }

    public static function label(string $column, array $strings): string {
        $aliases = ['meta_keyword' => ['entry_meta_keyword', 'entry_meta_keywords'], 'keyword' => ['entry_keyword', 'entry_seo_url'], 'customer_group_id' => ['entry_customer_group'], 'order_status_id' => ['entry_order_status'],
                    'manufacturer_id' => ['entry_manufacturer'], 'stock_status_id' => ['entry_stock_status'], 'tax_class_id' => ['entry_tax_class'], 'weight_class_id' => ['entry_weight_class'], 'length_class_id' => ['entry_length_class'],
                    'country_id' => ['entry_country'], 'zone_id' => ['entry_zone'], 'geo_zone_id' => ['entry_geo_zone'], 'tax_class_id' => ['entry_tax_class'], 'language_id' => ['entry_language'], 'store_id' => ['entry_store'], 'parent_id' => ['entry_parent'], 'date_added' => ['column_date_added', 'entry_date_added'],
                    'date_modified' => ['column_date_modified', 'entry_date_modified'], 'image' => ['entry_image'], 'bottom' => ['entry_bottom'], 'top' => ['entry_top'], 'quantity' => ['entry_quantity']];
        $keys = array_merge($aliases[$column] ?? [], ['entry_' . $column, 'column_' . $column, 'text_' . $column]);

        foreach ($keys as $k) {
            if (isset($strings[$k]) && is_string($strings[$k]) && trim(strip_tags($strings[$k])) !== '') {
                $label = rtrim(trim(strip_tags(html_entity_decode($strings[$k], ENT_QUOTES, 'UTF-8'))), ": \t");

                if ((function_exists('mb_strlen') ? mb_strlen($label) : strlen($label)) <= 48 && strpos($label, '. ') === false) {
                    return $label; // longer strings are help sentences, not labels
                }
            }
        }

        $human = str_replace('_', ' ', preg_replace('/_id$/', ' ID', $column));

        return ucwords($human); // Title Case like the store's own labels: "Date Modified", "Custom Field"
    }

    /**
     * The tabs of a form as the owner names them, in the form's order: General, Data, Links, Attributes, Options, Recurring payments,
     * Discounts, Specials, Images, Reward points, SEO, Featured. Fields stay on the tab the form puts them on (meta texts on General, the SEO URL on SEO).
     */
    public static function namedTabs(array $groups, callable $t): array {
        foreach ($groups as $i => $g) {
            $key = 'grp_' . str_replace('-', '_', $g['tab'] ?? ''); // 4.1 keeps specials on its Discount tab: it is "Discounts" there, as in the form
            $own = (string)$t($key);

            if ($own !== '' && $own !== $key) {
                $groups[$i]['title'] = $own;
            }
        }

        return array_values(array_filter($groups, function ($g) { return (bool)array_filter($g['fields'], function ($f) { return !$f['technical']; }); }));
    }

    /**
     * Everything the admin "Access" tab needs, as one JSON-able structure.
     *
     * @param array    $tables  existing unprefixed table name => row count
     * @param array    $columns unprefixed table name => [[name, type, key], ...]
     * @param callable $t       function(string $key): string - module language strings
     */
    public static function build(TablePolicy $policy, array $tables, array $columns, string $langRoot, string $langDir, callable $t, array $settingKeys = [], string $templateDir = ''): array {
        $out = [];
        $seen = [];

        // names that differ between versions (Recurring profiles in 3.x, Subscriptions in 4.x): key_oc3 / key_oc4 first
        $ver = isset($tables['subscription_plan']) || (defined('VERSION') && version_compare((string)constant('VERSION'), '4.0', '>=')) ? 'oc4' : 'oc3';
        $tv = function (string $key) use ($t, $ver) {
            $v = (string)$t($key . '_' . $ver);

            return $v !== '' ? $v : (string)$t($key);
        };

        $sections = self::SECTIONS;
        $sections['other'] = ['locked' => false, 'subs' => ['other' => ['menu' => false, 'lang' => [], 'tables' => array_values(array_diff(array_keys($tables), array_keys(self::tableIndex())))]]];

        foreach ($sections as $section => $def) {
            $subs = [];

            foreach ($def['subs'] as $sub => $s) {
                $present = array_values(array_filter($s['tables'], function ($tb) use ($tables) { return array_key_exists($tb, $tables); }));

                if (!$present) {
                    continue;
                }

                $strings = [];

                foreach ($s['lang'] as $file) {
                    $strings = array_merge(self::languageFile($langRoot, $langDir, $file), $strings);
                }

                $tbls = [];

                foreach ($present as $tb) {
                    $seen[$tb] = true;
                    $rule = $policy->rule($tb);
                    $cols = [];

                    $colList = $columns[$tb] ?? [];

                    if ($tb === 'setting') {
                        // settings are shown as the store's own config keys, not as the key/value columns of oc_setting
                        $colList = array_map(function ($k) { return [$k, 'setting', '']; }, $settingKeys);
                        $strings = array_merge(self::languageFile($langRoot, $langDir, 'setting/setting'), $strings);
                    }

                    foreach ($colList as $c) {
                        $name = $c[0];
                        $cols[] = [
                            'name'      => $name,
                            'label'     => self::label($name, $strings),
                            'type'      => $c[1],
                            'pk'        => $c[2] === 'PRI',
                            'technical' => $c[2] === 'PRI' || substr($name, -3) === '_id' || in_array($name, ['date_added', 'date_modified', 'sort_order'], true) || self::isComputed($tb, $name),
                            'personal'  => in_array($name, self::PERSONAL, true) || preg_match('/^(payment|shipping)_(firstname|lastname|company|address_1|address_2|city|postcode)$/', $name) === 1,
                            'money'     => in_array($name, self::MONEY, true) && !in_array($tb . '.' . $name, self::NOT_MONEY, true),
                            'secret'    => in_array(strtolower($name), $policy->credentialColumns($tb), true),
                            'no_edit'   => $tb === 'setting' ? !SettingsPolicy::canEditKey($name) : false,
                            'masked'    => in_array($name, TablePolicy::MASKED_COLUMNS[$tb] ?? [], true),
                        ];

                        if ($tb === 'setting') {
                            $last = count($cols) - 1;
                            $cols[$last]['label'] = self::label(substr($name, 7), $strings);
                            $cols[$last]['technical'] = false;
                        }

                        $own = (string)$t('fld_' . $tb . '_' . $name);

                        if ($own !== '') {
                            $cols[count($cols) - 1]['label'] = $own; // two fields of one record that the forms name alike ("IP" of a comment and of a rating)
                        }
                    }

                    $tl = (string)$t('tbl_' . $tb);
                    $tbls[] = [
                        'table'     => $tb,
                        'label'     => $tl !== 'tbl_' . $tb ? $tl : '', // what the records are, for "create and delete" in the fields dialog
                        'rows'      => (int)$tables[$tb],
                        'level'     => $rule['level'],
                        'max'       => TablePolicy::maxLevel($tb),
                        'columns'   => $rule['columns'],
                        'hidden'    => $rule['hidden'] ?? [],
                        'fields'    => $cols,
                        'sensitive' => TablePolicy::isSensitive($tb),
                    ];
                }

                // the admin form(s) of this menu item: field groups in the order and with the names the owner knows
                $groups = [];

                if ($templateDir !== '' && !empty($s['forms'])) {
                    $formCols = [];

                    foreach ($present as $tb) {
                        $formCols[$tb] = array_map(function ($c) { return $c[0]; }, $columns[$tb] ?? []);
                    }

                    if ($section . '.' . $sub === 'system.settings') {
                        $formCols = ['setting' => $settingKeys];
                    }

                    if (isset($columns['seo_url'])) {
                        $formCols['seo_url'] = array_map(function ($c) { return $c[0]; }, $columns['seo_url']);
                    }

                    $base = self::baseStrings($langRoot, $langDir);

                    foreach ($s['form_first'] ?? [] as $tb) {
                        if (isset($formCols[$tb])) {
                            $formCols = array_slice($formCols, 0, 1, true) + [$tb => $formCols[$tb]] + $formCols;
                        }
                    }

                    $byForm = [];

                    foreach ($s['forms'] as $form) {
                        $file = rtrim($templateDir, '/') . '/' . $form . '.twig';

                        if (!is_file($file)) {
                            continue;
                        }

                        $own = self::languageFile($langRoot, $langDir, preg_replace('/_form$/', '', $form));
                        $all = array_merge($base, $strings, $own);
                        $cols = $formCols;

                        if (isset(self::FORM_MAIN[$form], $cols[self::FORM_MAIN[$form]])) {
                            $cols = [self::FORM_MAIN[$form] => $cols[self::FORM_MAIN[$form]]] + $cols;
                        }

                        $twig = (string)file_get_contents($file);

                        foreach ($s['form_names'] ?? [] as $from => $to) { // the form's field is named like a stock table it does not write to (ocStore category: product_related[] -> product_related_wb)
                            $twig = str_replace('name="' . $from . '[]"', 'name="' . $to . '[]"', $twig);
                        }

                        $parsed = FormLayout::parse($twig, $cols, $all);
                        $heading = trim(strip_tags($own['heading_title'] ?? ''));

                        $parsed = self::namedTabs($parsed, $t);

                        if (!empty($s['form_heading'])) { // one part for many lists: each form is a section named after the list, in the module's language
                            $list = isset(self::FORM_MAIN[$form]) ? (string)$t('tbl_' . self::FORM_MAIN[$form]) : '';
                            $heading = $list !== '' && $list !== 'tbl_' . self::FORM_MAIN[$form] ? $list : $heading;

                            foreach ($heading === '' ? [] : $parsed as $pi => $g) {
                                $parsed[$pi]['title'] = count($parsed) > 1 ? $heading . ' › ' . $g['title'] : $heading;
                            }
                        }

                        foreach ($parsed as $g) {
                            if (isset(self::FORM_TITLES[$form]) && !empty($all[self::FORM_TITLES[$form]])) {
                                $g['title'] = trim(strip_tags($all[self::FORM_TITLES[$form]])); // a form without tabs (4.x address form): the tab of the customer form it opens from
                            }

                            $byForm[] = [$heading, $g];
                        }
                    }

                    $had = [];
                    $titles = [];

                    foreach ($byForm as [$heading, $g]) {
                        // a field shown by an earlier form stays there (the affiliate form repeats the customer's history and transactions)
                        $g['fields'] = array_values(array_filter($g['fields'], function ($f) use (&$had) {
                            $k = $f['table'] . '.' . $f['column'];
                            $new = !isset($had[$k]);
                            $had[$k] = true;

                            return $new;
                        }));

                        if (!array_filter($g['fields'], function ($f) { return !$f['technical']; })) {
                            continue;
                        }

                        if (isset($titles[$g['title']]) && $heading !== '') {
                            $g['title'] = $heading . ' › ' . $g['title']; // a second "General" tab: say whose
                        }

                        $titles[$g['title']] = true;
                        $groups[] = $g;
                    }
                }

                if ($section . '.' . $sub === 'system.settings' && $groups) { // only the store's own settings: extensions are never shown
                    $shown = [];

                    foreach ($groups as $gi => $g) {
                        foreach ($g['fields'] as $fi => $f) {
                            $shown[$f['column']] = true;
                            $groups[$gi]['fields'][$fi]['technical'] = false; // config_country_id, config_account_id ...: settings the owner sets, not row ids
                        }
                    }

                    $rest = [];

                    foreach ($tbls[0]['fields'] as $f) { // what the AI reads but no tab of the settings form shows: listed too, so Details tells the whole truth
                        if (!isset($shown[$f['name']])) {
                            $rest[] = ['table' => 'setting', 'column' => $f['name'], 'label' => $f['label'], 'technical' => false];
                        }
                    }

                    if ($rest) {
                        $groups[] = ['title' => (string)$t('grp_settings_other'), 'fields' => $rest];
                    }
                }

                if (($s['layout'] ?? '') === 'order') {
                    $groups = self::orderGroups($present, $columns, $strings, $t);
                }

                foreach ($groups as $gi => $g) { // the module's own name of a field wins over the form's, as in the field list above
                    foreach ($g['fields'] as $fi => $f) {
                        $own = (string)$t('fld_' . $f['table'] . '_' . $f['column']);

                        if ($own !== '') {
                            $groups[$gi]['fields'][$fi]['label'] = $own;
                        }
                    }
                }

                $named = 0;

                foreach ($groups as $g) {
                    $named += count(array_filter($g['fields'], function ($f) { return !$f['technical']; }));
                }

                if ($named < 2) {
                    $groups = []; // too little read from the form (e.g. a store form made of settings): list the fields instead
                }

                $subs[] = [
                    'key'     => $section . '.' . $sub,
                    'menu'    => $s['menu'] ?? true,
                    'groups'  => $groups,
                    'title'   => $tv('sub_' . $section . '_' . $sub),
                    'desc'    => $tv('subdesc_' . $section . '_' . $sub),
                    'tables'  => $tbls,
                ];
            }

            if ($subs) {
                $out[] = ['key' => $section, 'title' => $t('section_' . $section), 'locked' => $def['locked'], 'subs' => $subs];
            }
        }

        return $out;
    }

    /** Columns of all prefixed tables in one query (information_schema), as unprefixed table => [[name, type, key]]. */
    public static function loadColumns(Db $db): array {
        $out = [];
        $prefix = $db->prefix();
        $rows = $db->rows("SELECT `TABLE_NAME` AS t, `COLUMN_NAME` AS c, `COLUMN_TYPE` AS ty, `COLUMN_KEY` AS k FROM `information_schema`.`COLUMNS`"
            . " WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` LIKE " . $db->q(str_replace('_', '\\_', $prefix) . '%') . " ORDER BY `TABLE_NAME`, `ORDINAL_POSITION`");

        foreach ($rows as $r) {
            $short = $prefix !== '' && strpos($r['t'], $prefix) === 0 ? substr($r['t'], strlen($prefix)) : $r['t'];
            $out[$short][] = [$r['c'], $r['ty'], $r['k']];
        }

        return $out;
    }

    /** Row counts of all prefixed tables (approximate for InnoDB), unprefixed table => rows. */
    public static function loadTables(Db $db): array {
        $out = [];
        $prefix = $db->prefix();

        foreach ($db->rows("SELECT `TABLE_NAME` AS t, `TABLE_ROWS` AS r FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` LIKE " . $db->q(str_replace('_', '\\_', $prefix) . '%')) as $r) {
            $short = $prefix !== '' && strpos($r['t'], $prefix) === 0 ? substr($r['t'], strlen($prefix)) : $r['t'];
            $out[$short] = (int)$r['r'];
        }

        ksort($out);

        return $out;
    }
}
