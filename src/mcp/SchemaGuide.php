<?php
namespace OcMcp;

/**
 * How the OpenCart database of THIS store fits together: the main relations, the traps that make hand-written SQL wrong
 * (languages, escaped texts, "completed" order statuses, category paths, versions), and ready queries with this store's
 * prefix, language and statuses filled in. Saves the AI the exploring queries before every report, and its mistakes.
 * Delivered by db_schema (guide=true) and as the MCP resource opencart://schema-guide.
 */
final class SchemaGuide {
    const URI = 'opencart://schema-guide';

    /** @return array{version:string, relations:string[], traps:string[], recipes:array<int,array{title:string,sql:string}>} */
    public static function build(Context $c): array {
        $db = $c->db;
        $p = $db->prefix();
        $t = function (string $table) use ($p) { return '`' . $p . $table . '`'; };
        $L = (int)$c->languageId;
        $complete = array_values(array_filter(array_map('intval', (array)$c->config('config_complete_status', []))));
        $processing = array_values(array_filter(array_map('intval', (array)$c->config('config_processing_status', []))));
        $done = $complete ?: [5];
        $sold = implode(', ', array_unique(array_merge($done, $processing)));
        $doneList = implode(', ', $done);
        $oc4 = $c->ocMajor() >= 4;
        $oc41 = $c->hasTable('product_code');
        $ocstore = $c->hasColumn('product_description', 'meta_h1');
        $seoKey = $c->hasColumn('seo_url', 'key');
        $sku = $c->skuExpr('p');
        $special = $c->specialExpr('p');
        $views = $c->hasColumn('product', 'viewed') ? 'p.`viewed`' : ($c->hasTable('product_viewed') ? "(SELECT pv.`viewed` FROM " . $t('product_viewed') . " pv WHERE pv.`product_id` = p.`product_id`)" : 'NULL');

        $version = ($ocstore ? 'ocStore / ' : '') . 'OpenCart ' . ($c->ocVersion ?: ($oc4 ? '4' : '3')) . ($oc41 ? ' (4.1 schema)' : '');
        $relations = [
            "product 1-n product_description (product_id, language_id): name, description, meta_title, meta_description, meta_keyword, tag" . ($ocstore ? ', meta_h1' : ''),
            "product n-n category via product_to_category (product_id, category_id); category 1-n category_description (category_id, language_id)",
            "category tree: category.parent_id, and category_path (category_id, path_id = every ancestor incl. itself, level 0 = top) - use it for \"category with all subcategories\"",
            "product 1-n product_image (additional images, sort_order); main image in product.image; paths are relative to image/, e.g. catalog/x.jpg",
            "product -> manufacturer (manufacturer_id; manufacturer.name in the main table)" . ($c->hasTable('manufacturer_description') ? ', texts in manufacturer_description' : ''),
            "product 1-n product_to_store (store_id 0 = main store); a page is visible only if status = 1 and it is in product_to_store",
            $c->hasTable('product_special') ? "specials: product_special (customer_group_id, priority, price, date_start, date_end); volume discounts: product_discount (quantity > 1)"
                : "specials AND discounts: product_discount; special = 1 rows are specials, type P = percent off, S = amount off, F = fixed price; quantity = 1 for specials",
            "options: product_option -> product_option_value (price prefix +/-, quantity, subtract); attributes: product_attribute (attribute_id, language_id, text)",
            "order 1-n order_product (name, model, quantity, price = unit price ex tax, total = price x quantity ex tax, tax = per unit)",
            "order 1-n order_total (code: sub_total, shipping, coupon, voucher, reward, tax, total; value in the default currency, discounts negative)",
            "order 1-n order_history (order_status_id, notify, comment, date_added); order.order_status_id is the current status",
            "order_status (order_status_id, language_id, name); customer -> customer_group (customer_group_description per language)",
            "customer 1-n address; address -> country, zone" . ($c->hasTable('zone_description') ? ' (names in country_description / zone_description per language)' : ' (names in country.name / zone.name)'),
            "cart (session carts: customer_id, session_id, product_id, quantity, date_added) - what is in carts now, without an order",
            $seoKey ? "seo_url (store_id, language_id, key, value, keyword): key = product_id / path / information_id / manufacturer_id" . (ContentEntities::def($c, 'article') !== null ? ' / article_id / topic_id' : '') . "; categories use key = 'path', value = '20_27' and keyword = 'parent/child'"
                : "seo_url (store_id, language_id, query, keyword): query = 'product_id=42', 'category_id=20', 'information_id=4', 'manufacturer_id=8'",
            "marketing (name, description, code, clicks): campaign tracking links (the Examples field of the admin form) = " . rtrim($c->storeUrl, '/') . "/?tracking=CODE or " . rtrim($c->storeUrl, '/') . "/index.php?route=common/home&tracking=CODE; order.marketing_id = the campaign that brought the order; affiliates link the same way with customer_affiliate.tracking",
            "information 1-n information_description (title, description); setting (store_id, code, key, value, serialized) - read config through get_settings",
        ];

        if ($oc41) {
            $relations[] = "OpenCart 4.1: SKU / UPC / EAN / JAN / ISBN / MPN are rows of product_code (product_id, code, value), not product columns; variants: product.master_id (0 = master), product.variant / override JSON";
        }

        if (ContentEntities::def($c, 'article') !== null) {
            $relations[] = "blog (OpenCart 4.1): article (topic_id, author, status) 1-n article_description; topic 1-n topic_description; article_comment";
        }

        $traps = [
            "Texts are per language: always filter language_id (store default = $L) or you get one row per language.",
            "Texts are stored HTML-escaped (&amp; &lt;p&gt;) like the admin form saves them: decode when you show them; write texts with save_content / update_product, not raw SQL, so the store's content rules apply.",
            "Sales: count only orders with order_status_id IN ($sold) (completed: $doneList); order_status_id = 0 are abandoned checkouts and never sales.",
            "Money: prices and order totals are in the default currency (" . $c->currency . ") and without tax; order.currency_code / currency_value are only how the customer saw them.",
            "A product can be in several categories: revenue by category double-counts unless you say so; use DISTINCT for product counts.",
            "Dates: filter with ranges on the column (date_added >= '2026-09-01' AND date_added < '2026-10-01'), not DATE(date_added) = ..., so indexes work on big tables.",
            "Customers: prefer aggregates; personal data in answers is limited per hour.",
            "sql_query returns at most 200 rows: aggregate in SQL (GROUP BY, SUM, COUNT) instead of pulling raw rows.",
        ];

        if (!$c->hasTable('product_special')) {
            $traps[] = "This version has no product_special table: specials are product_discount rows with special = 1.";
        }

        if (!$oc4) {
            $traps[] = "OpenCart 3 tables are often MyISAM: there are no transactions, a failed multi-step change is not undone by the database (the module's journal is the undo).";
        }

        $since30 = "NOW() - INTERVAL 30 DAY";
        $recipes = [
            ['Revenue, orders and average order by day, last 30 days', "SELECT DATE(o.`date_added`) AS day, COUNT(*) AS orders, ROUND(SUM(o.`total`), 2) AS revenue, ROUND(AVG(o.`total`), 2) AS avg_order FROM " . $t('order') . " o WHERE o.`order_status_id` IN ($sold) AND o.`date_added` >= $since30 GROUP BY DATE(o.`date_added`) ORDER BY day LIMIT 200"],
            ['Top products by revenue (ex tax), last 30 days', "SELECT op.`product_id`, MAX(op.`name`) AS name, SUM(op.`quantity`) AS units, ROUND(SUM(op.`total`), 2) AS revenue FROM " . $t('order_product') . " op JOIN " . $t('order') . " o ON o.`order_id` = op.`order_id` WHERE o.`order_status_id` IN ($sold) AND o.`date_added` >= $since30 GROUP BY op.`product_id` ORDER BY revenue DESC LIMIT 20"],
            ['Revenue by top-level category, last 30 days (a product in two categories counts in both)', "SELECT cd.`name` AS category, COUNT(DISTINCT op.`product_id`) AS products, ROUND(SUM(op.`total`), 2) AS revenue FROM " . $t('order_product') . " op JOIN " . $t('order') . " o ON o.`order_id` = op.`order_id` JOIN " . $t('product_to_category') . " p2c ON p2c.`product_id` = op.`product_id` JOIN " . $t('category_path') . " cp ON cp.`category_id` = p2c.`category_id` AND cp.`level` = 0 JOIN " . $t('category_description') . " cd ON cd.`category_id` = cp.`path_id` AND cd.`language_id` = $L WHERE o.`order_status_id` IN ($sold) AND o.`date_added` >= $since30 GROUP BY cp.`path_id`, cd.`name` ORDER BY revenue DESC LIMIT 50"],
            ['ABC: each product\'s share of revenue, last 90 days (cumulate in your answer: A = first 80 %)', "SELECT op.`product_id`, MAX(op.`name`) AS name, ROUND(SUM(op.`total`), 2) AS revenue, ROUND(100 * SUM(op.`total`) / (SELECT SUM(op2.`total`) FROM " . $t('order_product') . " op2 JOIN " . $t('order') . " o2 ON o2.`order_id` = op2.`order_id` WHERE o2.`order_status_id` IN ($sold) AND o2.`date_added` >= NOW() - INTERVAL 90 DAY), 2) AS share_pct FROM " . $t('order_product') . " op JOIN " . $t('order') . " o ON o.`order_id` = op.`order_id` WHERE o.`order_status_id` IN ($sold) AND o.`date_added` >= NOW() - INTERVAL 90 DAY GROUP BY op.`product_id` ORDER BY revenue DESC LIMIT 200"],
            ['Products of a category with all its subcategories (replace 20)', "SELECT DISTINCT p.`product_id`, pd.`name`, p.`price`, p.`quantity`, p.`status` FROM " . $t('category_path') . " cp JOIN " . $t('product_to_category') . " p2c ON p2c.`category_id` = cp.`category_id` JOIN " . $t('product') . " p ON p.`product_id` = p2c.`product_id` JOIN " . $t('product_description') . " pd ON pd.`product_id` = p.`product_id` AND pd.`language_id` = $L WHERE cp.`path_id` = 20 LIMIT 200"],
            ['Category tree with full paths', "SELECT cp.`category_id`, GROUP_CONCAT(cd.`name` ORDER BY cp.`level` SEPARATOR ' > ') AS path, MAX(c.`status`) AS status FROM " . $t('category_path') . " cp JOIN " . $t('category_description') . " cd ON cd.`category_id` = cp.`path_id` AND cd.`language_id` = $L JOIN " . $t('category') . " c ON c.`category_id` = cp.`category_id` GROUP BY cp.`category_id` ORDER BY path LIMIT 200"],
            ['Enabled products without a main or additional image', "SELECT p.`product_id`, pd.`name`, p.`image` FROM " . $t('product') . " p JOIN " . $t('product_description') . " pd ON pd.`product_id` = p.`product_id` AND pd.`language_id` = $L WHERE p.`status` = 1 AND (p.`image` IS NULL OR p.`image` = '' OR NOT EXISTS (SELECT 1 FROM " . $t('product_image') . " pi WHERE pi.`product_id` = p.`product_id`)) LIMIT 200"],
            ['SEO texts per language: empty or too long meta tags, thin descriptions', "SELECT pd.`language_id`, SUM(pd.`meta_title` = '') AS no_meta_title, SUM(CHAR_LENGTH(pd.`meta_title`) > 60) AS long_meta_title, SUM(pd.`meta_description` = '') AS no_meta_description, SUM(CHAR_LENGTH(pd.`description`) < 300) AS thin_description, COUNT(*) AS products FROM " . $t('product_description') . " pd JOIN " . $t('product') . " p ON p.`product_id` = pd.`product_id` AND p.`status` = 1 GROUP BY pd.`language_id` LIMIT 20"],
            ['Duplicate meta titles', "SELECT pd.`language_id`, pd.`meta_title`, COUNT(*) AS pages, GROUP_CONCAT(pd.`product_id` ORDER BY pd.`product_id`) AS product_ids FROM " . $t('product_description') . " pd WHERE pd.`meta_title` <> '' GROUP BY pd.`language_id`, pd.`meta_title` HAVING COUNT(*) > 1 ORDER BY pages DESC LIMIT 100"],
            ['Products without an SEO address in the default language', $seoKey
                ? "SELECT p.`product_id`, pd.`name` FROM " . $t('product') . " p JOIN " . $t('product_description') . " pd ON pd.`product_id` = p.`product_id` AND pd.`language_id` = $L LEFT JOIN " . $t('seo_url') . " su ON su.`key` = 'product_id' AND su.`value` = p.`product_id` AND su.`language_id` = $L AND su.`store_id` = 0 WHERE p.`status` = 1 AND su.`seo_url_id` IS NULL LIMIT 200"
                : "SELECT p.`product_id`, pd.`name` FROM " . $t('product') . " p JOIN " . $t('product_description') . " pd ON pd.`product_id` = p.`product_id` AND pd.`language_id` = $L LEFT JOIN " . $t('seo_url') . " su ON su.`query` = CONCAT('product_id=', p.`product_id`) AND su.`store_id` = 0 WHERE p.`status` = 1 AND su.`seo_url_id` IS NULL LIMIT 200"],
            ['Stock: days left at the sales rate of the last 60 days', "SELECT p.`product_id`, pd.`name`, p.`quantity`, ROUND(COALESCE(s.units, 0) / 60, 2) AS per_day, CASE WHEN COALESCE(s.units, 0) = 0 THEN NULL ELSE ROUND(p.`quantity` / (s.units / 60)) END AS days_left FROM " . $t('product') . " p JOIN " . $t('product_description') . " pd ON pd.`product_id` = p.`product_id` AND pd.`language_id` = $L LEFT JOIN (SELECT op.`product_id`, SUM(op.`quantity`) AS units FROM " . $t('order_product') . " op JOIN " . $t('order') . " o ON o.`order_id` = op.`order_id` WHERE o.`order_status_id` IN ($sold) AND o.`date_added` >= NOW() - INTERVAL 60 DAY GROUP BY op.`product_id`) s ON s.`product_id` = p.`product_id` WHERE p.`status` = 1 AND s.units > 0 ORDER BY days_left LIMIT 100"],
            ['Dead stock: in stock, no sales for 90 days, by money tied up', "SELECT p.`product_id`, pd.`name`, p.`quantity`, p.`price`, ROUND(p.`quantity` * p.`price`, 2) AS stock_value, $views AS views FROM " . $t('product') . " p JOIN " . $t('product_description') . " pd ON pd.`product_id` = p.`product_id` AND pd.`language_id` = $L WHERE p.`status` = 1 AND p.`quantity` > 0 AND NOT EXISTS (SELECT 1 FROM " . $t('order_product') . " op JOIN " . $t('order') . " o ON o.`order_id` = op.`order_id` WHERE op.`product_id` = p.`product_id` AND o.`order_status_id` IN ($sold) AND o.`date_added` >= NOW() - INTERVAL 90 DAY) ORDER BY stock_value DESC LIMIT 100"],
            ['Customers: new vs returning, last 30 days (aggregates, no personal data)', "SELECT SUM(x.first_order >= $since30) AS new_customers, SUM(x.first_order < $since30) AS returning_customers, ROUND(AVG(x.orders), 2) AS avg_orders_per_customer FROM (SELECT o.`customer_id`, MIN(o.`date_added`) AS first_order, COUNT(*) AS orders FROM " . $t('order') . " o WHERE o.`order_status_id` IN ($sold) AND o.`customer_id` > 0 GROUP BY o.`customer_id` HAVING MAX(o.`date_added`) >= $since30) x"],
            ['Abandoned carts: products left in carts during the last 30 days', "SELECT c.`product_id`, MAX(pd.`name`) AS name, COUNT(*) AS carts, SUM(c.`quantity`) AS units, SUM(c.`customer_id` > 0) AS registered FROM " . $t('cart') . " c LEFT JOIN " . $t('product_description') . " pd ON pd.`product_id` = c.`product_id` AND pd.`language_id` = $L WHERE c.`date_added` >= $since30 GROUP BY c.`product_id` ORDER BY carts DESC LIMIT 50"],
            ['Discounts given: coupons, vouchers, reward points, last 30 days', "SELECT ot.`code`, COUNT(*) AS orders, ROUND(SUM(ot.`value`), 2) AS amount FROM " . $t('order_total') . " ot JOIN " . $t('order') . " o ON o.`order_id` = ot.`order_id` WHERE ot.`code` IN ('coupon', 'voucher', 'reward') AND o.`order_status_id` IN ($sold) AND o.`date_added` >= $since30 GROUP BY ot.`code` LIMIT 20"],
            ['Products with an active special for the default customer group', "SELECT p.`product_id`, pd.`name`, p.`price`, $special AS special_price FROM " . $t('product') . " p JOIN " . $t('product_description') . " pd ON pd.`product_id` = p.`product_id` AND pd.`language_id` = $L WHERE p.`status` = 1 HAVING special_price IS NOT NULL LIMIT 200"],
            ['Product with SKU, manufacturer and stock status', "SELECT p.`product_id`, pd.`name`, p.`model`, $sku AS sku, m.`name` AS manufacturer, p.`quantity`, ss.`name` AS stock_status FROM " . $t('product') . " p JOIN " . $t('product_description') . " pd ON pd.`product_id` = p.`product_id` AND pd.`language_id` = $L LEFT JOIN " . $t('manufacturer') . " m ON m.`manufacturer_id` = p.`manufacturer_id` LEFT JOIN " . $t('stock_status') . " ss ON ss.`stock_status_id` = p.`stock_status_id` AND ss.`language_id` = $L ORDER BY p.`product_id` LIMIT 50"],
            ['Orders by status in the last 30 days, with status names', "SELECT o.`order_status_id`, COALESCE(os.`name`, 'missing (abandoned checkout)') AS status, COUNT(*) AS orders, ROUND(SUM(o.`total`), 2) AS total FROM " . $t('order') . " o LEFT JOIN " . $t('order_status') . " os ON os.`order_status_id` = o.`order_status_id` AND os.`language_id` = $L WHERE o.`date_added` >= $since30 GROUP BY o.`order_status_id`, os.`name` ORDER BY orders DESC LIMIT 50"],
            ['Sales by customer region, last 90 days', "SELECT o.`payment_country` AS country, o.`payment_zone` AS region, COUNT(*) AS orders, ROUND(SUM(o.`total`), 2) AS revenue FROM " . $t('order') . " o WHERE o.`order_status_id` IN ($sold) AND o.`date_added` >= NOW() - INTERVAL 90 DAY GROUP BY o.`payment_country`, o.`payment_zone` ORDER BY revenue DESC LIMIT 50"],
        ];

        if (!$c->hasColumn('order', 'payment_zone')) {
            $recipes[18] = ['Sales by customer region, last 90 days', "SELECT o.`shipping_country` AS country, o.`shipping_zone` AS region, COUNT(*) AS orders, ROUND(SUM(o.`total`), 2) AS revenue FROM " . $t('order') . " o WHERE o.`order_status_id` IN ($sold) AND o.`date_added` >= NOW() - INTERVAL 90 DAY GROUP BY o.`shipping_country`, o.`shipping_zone` ORDER BY revenue DESC LIMIT 50"];
        }

        return ['version' => $version, 'store_language_id' => $L, 'sold_status_ids' => array_map('intval', explode(', ', $sold)), 'relations' => $relations, 'traps' => $traps,
                'recipes' => array_map(function ($r) { return ['title' => $r[0], 'sql' => $r[1]]; }, $recipes)];
    }

    /** The same as Markdown, for the MCP resource. */
    public static function markdown(Context $c): string {
        $g = self::build($c);
        $md = "# Database guide: " . $g['version'] . "\n\nPrefix `" . $c->db->prefix() . "`, default language_id " . $g['store_language_id'] . ", sales = order_status_id IN (" . implode(', ', $g['sold_status_ids']) . ").\n\n## Relations\n";

        foreach ($g['relations'] as $r) {
            $md .= "- $r\n";
        }

        $md .= "\n## Traps\n";

        foreach ($g['traps'] as $r) {
            $md .= "- $r\n";
        }

        $md .= "\n## Ready queries (run with sql_query, adjust periods and ids)\n";

        foreach ($g['recipes'] as $r) {
            $md .= "\n### " . $r['title'] . "\n```sql\n" . $r['sql'] . "\n```\n";
        }

        return $md;
    }
}
