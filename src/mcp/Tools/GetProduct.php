<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class GetProduct extends Tool {
    public function name(): string {
        return 'get_product';
    }

    public function tables(): array {
        return ['product', 'product_description'];
    }

    public function fieldsKey(): ?string {
        return 'product_id';
    }

    public function describe(): array {
        return [
            'title'       => 'Get product details',
            'description' => 'Full details of one product: all fields, product codes (SKU/UPC/EAN...), descriptions in every language (plain text), categories, specials, quantity discounts, options, images, attributes and SEO keywords.',
            'inputSchema' => self::schema([
                'product_id'       => ['type' => 'integer'],
                'full_description' => self::prop('boolean', 'Return the complete HTML description instead of a 1500-character plain-text excerpt (default false).'),
            ], ['product_id']),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $this->need($a, 'product_id');
        $id = (int)$this->int($a, 'product_id');
        $full = (bool)$this->bool($a, 'full_description', false);
        $db = $c->db;
        $L = (int)$c->languageId;

        $policy = $c->policy();
        $can = function (string ...$tables) use ($policy): bool { foreach ($tables as $t) { if (!$policy->canRead($t)) { return false; } } return true; };
        $closed = []; // parts of the store the owner closed: left out of the answer, and said so
        $p = $db->row("SELECT p.*, " . ($can('manufacturer') ? "m.`name`" : "''") . " AS manufacturer FROM " . $db->t('product') . " p" . ($can('manufacturer') ? " LEFT JOIN " . $db->t('manufacturer') . " m ON m.`manufacturer_id` = p.`manufacturer_id`" : '') . " WHERE p.`product_id` = " . $id);

        if ($p === null) {
            return ToolResult::error("Product $id not found.");
        }

        // product codes: columns on product (OC <= 4.0) or rows in product_code (OC 4.1+)
        $codes = [];

        foreach (['sku', 'upc', 'ean', 'jan', 'isbn', 'mpn'] as $code) {
            if (array_key_exists($code, $p) && (string)$p[$code] !== '') {
                $codes[strtoupper($code)] = (string)$p[$code];
            }
        }

        if (!array_key_exists('sku', $p) && $c->hasTable('product_code')) {
            foreach ($db->rows("SELECT `code`, `value` FROM " . $db->t('product_code') . " WHERE `product_id` = " . $id) as $row) {
                if ((string)$row['value'] !== '') {
                    $codes[strtoupper((string)$row['code'])] = (string)$row['value'];
                }
            }
        }

        $descriptions = [];

        foreach ($db->rows("SELECT pd.*, " . ($can('language') ? "l.`code`" : "''") . " AS language_code FROM " . $db->t('product_description') . " pd" . ($can('language') ? " LEFT JOIN " . $db->t('language') . " l ON l.`language_id` = pd.`language_id`" : '') . " WHERE pd.`product_id` = " . $id) as $d) {
            $descriptions[] = [
                'language_id'      => (int)$d['language_id'],
                'language'         => (string)$d['language_code'],
                'name'             => (string)$d['name'],
                'description'      => $full ? (string)$d['description'] : self::plainText($d['description']),
                'tag'              => (string)($d['tag'] ?? ''),
                'meta_title'       => (string)$d['meta_title'],
                'meta_description' => (string)$d['meta_description'],
                'meta_keyword'     => (string)$d['meta_keyword'],
            ];
        }

        if ($can('category', 'category_description')) {
            $categories = $db->rows("SELECT c.`category_id`, cd.`name` FROM " . $db->t('product_to_category') . " p2c"
                . " INNER JOIN " . $db->t('category') . " c ON c.`category_id` = p2c.`category_id`"
                . " LEFT JOIN " . $db->t('category_description') . " cd ON cd.`category_id` = c.`category_id` AND cd.`language_id` = " . $L
                . " WHERE p2c.`product_id` = " . $id);
        } else { // the product's own links stay; the names belong to Categories
            $categories = $db->rows("SELECT `category_id` FROM " . $db->t('product_to_category') . " WHERE `product_id` = " . $id);
            $closed[] = 'category names';
        }

        if ($c->hasSpecialTable()) {
            $specials = $db->rows("SELECT `product_special_id`, `customer_group_id`, `priority`, `price`, `date_start`, `date_end` FROM " . $db->t('product_special') . " WHERE `product_id` = " . $id . " ORDER BY `priority`, `price`");
            $discounts = $db->rows("SELECT `product_discount_id`, `customer_group_id`, `quantity`, `priority`, `price`, `date_start`, `date_end` FROM " . $db->t('product_discount') . " WHERE `product_id` = " . $id . " ORDER BY `quantity`");
        } else {
            // OC 4.1+: one table, `special` flag, `type` P = percent off, S = subtract amount, otherwise fixed price
            $specials = $db->rows("SELECT `product_discount_id` AS product_special_id, `customer_group_id`, `priority`, `price`, `type`, `date_start`, `date_end` FROM " . $db->t('product_discount') . " WHERE `product_id` = " . $id . " AND `special` = 1 ORDER BY `priority`, `price`");
            $discounts = $db->rows("SELECT `product_discount_id`, `customer_group_id`, `quantity`, `priority`, `price`, `type`, `date_start`, `date_end` FROM " . $db->t('product_discount') . " WHERE `product_id` = " . $id . " AND `special` = 0 ORDER BY `quantity`");
        }

        $images = $db->rows("SELECT `image`, `sort_order` FROM " . $db->t('product_image') . " WHERE `product_id` = " . $id . " ORDER BY `sort_order`");

        $options = !$can('option', 'option_description', 'option_value_description') ? [] : $db->rows("SELECT po.`product_option_id`, po.`option_id`, od.`name`, o.`type`, po.`required`,"
            . " (SELECT COUNT(*) FROM " . $db->t('product_option_value') . " pov WHERE pov.`product_option_id` = po.`product_option_id`) AS value_count"
            . " FROM " . $db->t('product_option') . " po"
            . " LEFT JOIN " . $db->t('option') . " o ON o.`option_id` = po.`option_id`"
            . " LEFT JOIN " . $db->t('option_description') . " od ON od.`option_id` = po.`option_id` AND od.`language_id` = " . $L
            . " WHERE po.`product_id` = " . $id);

        // one query for all option values (no N+1)
        $valuesByOption = [];

        if ($options) {
            $ids = array_map(function ($o) { return (int)$o['product_option_id']; }, $options);

            foreach ($db->rows("SELECT pov.`product_option_id`, pov.`product_option_value_id`, pov.`option_value_id`, ovd.`name`, pov.`quantity`, pov.`subtract`, pov.`price`, pov.`price_prefix`"
                . " FROM " . $db->t('product_option_value') . " pov"
                . " LEFT JOIN " . $db->t('option_value_description') . " ovd ON ovd.`option_value_id` = pov.`option_value_id` AND ovd.`language_id` = " . $L
                . " WHERE pov.`product_option_id` IN (" . $db->ints($ids) . ") ORDER BY ovd.`name`") as $v) {
                $valuesByOption[(int)$v['product_option_id']][] = $v;
            }
        }

        foreach ($options as &$opt) {
            $opt['values'] = $valuesByOption[(int)$opt['product_option_id']] ?? [];
        }
        unset($opt);

        if (!$can('option', 'option_description', 'option_value_description')) {
            $closed[] = 'options';
        }

        if (!$can('attribute_description')) {
            $closed[] = 'attributes';
        }

        $attributes = !$can('attribute_description') ? [] : $db->rows("SELECT pa.`attribute_id`, ad.`name`, pa.`text` FROM " . $db->t('product_attribute') . " pa"
            . " LEFT JOIN " . $db->t('attribute_description') . " ad ON ad.`attribute_id` = pa.`attribute_id` AND ad.`language_id` = " . $L
            . " WHERE pa.`product_id` = " . $id . " AND pa.`language_id` = " . $L);

        if (!$can('seo_url')) {
            $seo = [];
            $closed[] = 'SEO keywords';
        } elseif ($c->hasColumn('seo_url', 'key')) {
            $seo = $db->rows("SELECT `language_id`, `keyword` FROM " . $db->t('seo_url') . " WHERE `key` = 'product_id' AND `value` = '" . $id . "'");
        } else {
            $seo = $db->rows("SELECT `language_id`, `keyword` FROM " . $db->t('seo_url') . " WHERE `query` = 'product_id=" . $id . "'");
        }

        $stockStatus = $can('stock_status') ? $db->value("SELECT `name` FROM " . $db->t('stock_status') . " WHERE `stock_status_id` = " . (int)$p['stock_status_id'] . " AND `language_id` = " . $L, 'name') : '';

        if (!$can('manufacturer')) {
            $closed[] = 'manufacturer name';
        }

        $data = [
            'product_id'       => (int)$p['product_id'],
            'name'             => $descriptions ? $this->nameFor($descriptions, $L) : '',
            'model'            => (string)$p['model'],
            'sku'              => $codes['SKU'] ?? '',
            'codes'            => $codes,
            'location'         => (string)($p['location'] ?? ''),
            'price'            => (float)$p['price'],
            'quantity'         => (int)$p['quantity'],
            'subtract_stock'   => (bool)$p['subtract'],
            'minimum'          => (int)$p['minimum'],
            'stock_status_id'  => (int)$p['stock_status_id'],
            'stock_status'     => (string)$stockStatus,
            'status'           => (bool)$p['status'],
            'manufacturer_id'  => (int)$p['manufacturer_id'],
            'manufacturer'     => (string)$p['manufacturer'],
            'tax_class_id'     => (int)$p['tax_class_id'],
            'shipping'         => (bool)$p['shipping'],
            'weight'           => (float)$p['weight'],
            'weight_class_id'  => (int)$p['weight_class_id'],
            'dimensions'       => ['length' => (float)$p['length'], 'width' => (float)$p['width'], 'height' => (float)$p['height'], 'length_class_id' => (int)$p['length_class_id']],
            'points'           => (int)$p['points'],
            'sort_order'       => (int)$p['sort_order'],
            'image'            => (string)$p['image'],
            'date_available'   => (string)$p['date_available'],
            'date_added'       => (string)$p['date_added'],
            'date_modified'    => (string)$p['date_modified'],
            'descriptions'     => $descriptions,
            'categories'       => $categories,
            'specials'         => $specials,
            'discounts'        => $discounts,
            'options'          => $options,
            'attributes'       => $attributes,
            'additional_images'=> $images,
            'seo_keywords'     => $seo,
            'currency'         => $c->currency,
        ];

        if (array_key_exists('master_id', $p)) {
            $data['master_id'] = (int)$p['master_id'];
        }

        if ($closed) {
            $data['not_shown'] = 'closed for the AI by the store owner: ' . implode(', ', $closed);
        }

        return ToolResult::data($data);
    }

    private function nameFor(array $descriptions, int $languageId): string {
        foreach ($descriptions as $d) {
            if ($d['language_id'] === $languageId) {
                return $d['name'];
            }
        }

        return (string)$descriptions[0]['name'];
    }
}
