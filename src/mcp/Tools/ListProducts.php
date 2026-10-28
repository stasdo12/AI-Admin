<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class ListProducts extends Tool {
    public function name(): string {
        return 'list_products';
    }

    public function tables(): array {
        return ['product', 'product_description'];
    }

    public function fieldsKey(): ?string {
        return 'product_id';
    }

    public function describe(): array {
        return [
            'title'       => 'Search / list products',
            'description' => 'List products with price, special, stock and status. Filter by text (name, model, SKU, brand words), category, manufacturer, status, stock, price. Paginated.',
            'inputSchema' => self::schema([
                'search'          => self::prop('string', 'Text to search in product name, model and SKU/product codes; a bare number also matches product_id.'),
                'product_ids'     => self::prop('array', 'Return only these product IDs.', ['items' => ['type' => 'integer']]),
                'category_id'     => self::prop('integer', 'Only products assigned to this category.'),
                'manufacturer_id' => self::prop('integer', 'Only products of this manufacturer (brand).'),
                'store_id'        => self::prop('integer', 'Only products shown in this store.'),
                'status'          => self::prop('boolean', 'true = enabled only, false = disabled only.'),
                'in_stock'        => self::prop('boolean', 'true = quantity > 0 only, false = quantity <= 0 only.'),
                'min_price'       => self::prop('number', 'Minimum regular price.'),
                'max_price'       => self::prop('number', 'Maximum regular price.'),
                'sort'            => self::prop('string', 'Sort field.', ['enum' => ['name', 'price', 'quantity', 'date_added', 'date_modified', 'product_id', 'model']]),
                'order'           => self::prop('string', 'Sort direction.', ['enum' => ['asc', 'desc']]),
                'limit'           => self::prop('integer', 'Page size, 1-200 (default 25).'),
                'offset'          => self::prop('integer', 'Offset for pagination (default 0).'),
            ]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $db = $c->db;
        $L = (int)$c->languageId;

        $where = ['1'];
        $join = '';

        $search = $this->str($a, 'search');

        if ($search !== null && $search !== '') {
            $like = $db->like($search);
            $searchAt = count($where);
            $where[] = "(pd.`name` LIKE $like OR p.`model` LIKE $like OR " . $c->codeLikeCondition($like) . " OR p.`product_id` = " . (int)$search . ")";
        }

        if (!empty($a['product_ids']) && is_array($a['product_ids'])) {
            $where[] = "p.`product_id` IN (" . $db->ints($a['product_ids']) . ")";
        }

        $categoryId = $this->int($a, 'category_id');

        if ($categoryId) {
            $join .= " INNER JOIN " . $db->t('product_to_category') . " p2c ON p2c.`product_id` = p.`product_id` AND p2c.`category_id` = " . $categoryId;
        }

        $manufacturerId = $this->int($a, 'manufacturer_id');

        if ($manufacturerId) {
            $where[] = "p.`manufacturer_id` = " . $manufacturerId;
        }

        if (array_key_exists('store_id', $a) && $a['store_id'] !== null && $a['store_id'] !== '') {
            $storeId = $c->storeIds($a['store_id'], 'store_id')[0];
            $where[] = "p.`product_id` IN (SELECT `product_id` FROM " . $db->t('product_to_store') . " WHERE `store_id` = " . $storeId . ")";
        }

        $status = $this->bool($a, 'status');

        if ($status !== null) {
            $where[] = "p.`status` = " . ($status ? 1 : 0);
        }

        $inStock = $this->bool($a, 'in_stock');

        if ($inStock !== null) {
            $where[] = $inStock ? "p.`quantity` > 0" : "p.`quantity` <= 0";
        }

        $min = $this->num($a, 'min_price');
        $max = $this->num($a, 'max_price');

        if ($min !== null) {
            $where[] = "p.`price` >= " . (float)$min;
        }

        if ($max !== null) {
            $where[] = "p.`price` <= " . (float)$max;
        }

        $sortMap = [
            'name'          => 'pd.`name`',
            'price'         => 'p.`price`',
            'quantity'      => 'p.`quantity`',
            'date_added'    => 'p.`date_added`',
            'date_modified' => 'p.`date_modified`',
            'product_id'    => 'p.`product_id`',
            'model'         => 'p.`model`',
        ];
        $sort = $sortMap[$this->enum($a, 'sort', array_keys($sortMap), 'product_id')];
        $order = strtoupper($this->enum($a, 'order', ['asc', 'desc'], 'desc'));
        $limit = $this->limit($a);
        $offset = $this->offset($a);

        $from = " FROM " . $db->t('product') . " p"
            . " LEFT JOIN " . $db->t('product_description') . " pd ON pd.`product_id` = p.`product_id` AND pd.`language_id` = " . $L
            . $join
            . " WHERE " . implode(' AND ', $where);

        $total = (int)$db->value("SELECT COUNT(DISTINCT p.`product_id`) AS c" . $from, 'c');
        $match = null;
        $words = isset($searchAt) ? array_slice(array_values(array_filter(preg_split('/[\s,]+/u', (string)$search), function ($w) { return mb_strlen($w) >= 2; })), 0, 8) : [];

        if ($total === 0 && count($words) >= 2) {
            // "Carolina Herrera 212 Men NYC" for a product named "212 Men" of brand Carolina Herrera: first every word in the
            // name, model or brand; then the products sharing the most words, marked as only similar
            $join .= " LEFT JOIN " . $db->t('manufacturer') . " m ON m.`manufacturer_id` = p.`manufacturer_id`";
            $conds = array_map(function ($w) use ($db) { $l = $db->like($w); return "(pd.`name` LIKE $l OR p.`model` LIKE $l OR m.`name` LIKE $l)"; }, $words);

            foreach (['words' => implode(' AND ', $conds), 'similar' => implode(' OR ', $conds)] as $kind => $cond) {
                $where[$searchAt] = '(' . $cond . ')';
                $from = " FROM " . $db->t('product') . " p"
                    . " LEFT JOIN " . $db->t('product_description') . " pd ON pd.`product_id` = p.`product_id` AND pd.`language_id` = " . $L
                    . $join
                    . " WHERE " . implode(' AND ', $where);
                $total = (int)$db->value("SELECT COUNT(DISTINCT p.`product_id`) AS c" . $from, 'c');

                if ($total > 0) {
                    $match = $kind;

                    if ($kind === 'similar') {
                        $sort = '(' . implode(' + ', $conds) . ')'; // most shared words first
                        $order = 'DESC';
                    }

                    break;
                }
            }
        }

        $master = $c->hasColumn('product', 'master_id') ? "p.`master_id`" : "0 AS master_id";

        // step 1: only the ids of the requested page (cheap sort on plain columns); step 2: SKU / special price
        // sub-queries for those ids only, never for every product in the store
        $ids = array_map('intval', array_column($db->rows("SELECT p.`product_id`" . $from . " GROUP BY p.`product_id` ORDER BY " . $sort . " " . $order . ", p.`product_id` " . $order . " LIMIT " . $offset . ", " . $limit), 'product_id'));
        $rows = [];

        if ($ids) {
            $byId = [];

            foreach ($db->rows("SELECT p.`product_id`, " . $master . ", pd.`name`, p.`model`, " . $c->skuExpr() . " AS sku, p.`price`, " . $c->specialExpr() . " AS special, p.`quantity`, p.`status`, p.`manufacturer_id`, p.`image`, p.`date_modified`"
                . " FROM " . $db->t('product') . " p LEFT JOIN " . $db->t('product_description') . " pd ON pd.`product_id` = p.`product_id` AND pd.`language_id` = " . $L
                . " WHERE p.`product_id` IN (" . implode(',', $ids) . ")") as $r) {
                $byId[(int)$r['product_id']] = $r;
            }

            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $rows[] = $byId[$id];
                }
            }
        }

        $items = [];

        foreach ($rows as $r) {
            $item = [
                'product_id'      => (int)$r['product_id'],
                'name'            => (string)$r['name'],
                'model'           => (string)$r['model'],
                'sku'             => (string)$r['sku'],
                'price'           => (float)$r['price'],
                'special'         => $r['special'] !== null ? round((float)$r['special'], 4) : null,
                'quantity'        => (int)$r['quantity'],
                'status'          => (bool)$r['status'],
                'manufacturer_id' => (int)$r['manufacturer_id'],
                'image'           => (string)$r['image'],
                'date_modified'   => (string)$r['date_modified'],
            ];

            if (!empty($r['master_id'])) {
                $item['master_id'] = (int)$r['master_id']; // OC 4.x variant of another product
            }

            $items[] = $item;
        }

        return ToolResult::data([
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'currency' => $c->currency,
            'items'    => $items,
        ] + ($match ? ['match' => $match] : []), "Found $total product(s); showing " . count($items) . " from offset $offset."
            . ($match === 'words' ? ' No product has this exact text; these have every word of it in the name, model or brand.' : '')
            . ($match === 'similar' ? ' NO MATCH for the whole text: these only share some words with it (most first). Check the names before treating one as the same product.' : ''));
    }
}
