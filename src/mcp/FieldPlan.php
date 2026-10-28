<?php
namespace OcMcp;

/**
 * Which fields of a page the AI fills, asks about or leaves alone, and which stayed empty after a save.
 *
 * The logs showed the AI filling name, description and meta but never the H1 (meta_h1), the SKU, the photo or a brand's
 * second description: nothing told it these fields exist and matter. Each field has a fixed rule and a hint how to fill
 * it - built in, not a setting (a second table next to the Access tab only confused owners, 2026-10-13). Only the fields
 * this store has AND the Access tab lets the AI write are listed: a closed field is never asked about or reported empty.
 */
final class FieldPlan {
    const FILL = 'fill';
    const ASK = 'ask';
    const SKIP = 'skip';
    const RULES = [self::FILL, self::ASK, self::SKIP];

    /** Where a field is set: texts (per language), data (a column of the page), seo, or a related list. */
    const CATALOG = [
        'product' => [
            'name'             => ['texts', self::FILL, 'brand, product name and the variant (volume, size, colour)'],
            'description'      => ['texts', self::FILL, 'intro, benefits as a list, specifications as a table; h2-h3, no h1'],
            'meta_title'       => ['texts', self::FILL, '30-60 characters, key phrase first, not a copy of the name'],
            'meta_description' => ['texts', self::FILL, '120-160 characters: what it is, the main benefit, a call to action'],
            'meta_keyword'     => ['texts', self::FILL, 'a few key phrases, comma-separated'],
            'meta_h1'          => ['texts', self::FILL, 'the heading shown on the page (H1): the product name, it may differ from meta_title'],
            'tag'              => ['texts', self::FILL, 'comma-separated tags for the store search'],
            'seo_keyword'      => ['seo', self::FILL, 'made from the name when left out'],
            'model'            => ['data', self::FILL, 'required by OpenCart: a short code, e.g. BRAND-NAME-5ML, unique per variant'],
            'sku'              => ['data', self::ASK, 'the store\'s article number: never invent it; the same as the model or empty, as the user says'],
            'upc'              => ['data', self::SKIP, 'barcode: only from the user\'s data'],
            'ean'              => ['data', self::SKIP, 'barcode: only from the user\'s data'],
            'mpn'              => ['data', self::SKIP, 'manufacturer part number: only from the user\'s data'],
            'price'            => ['data', self::ASK, 'only from the user: a value, a price list, a rule ("+10%", "per ml"), or a task like "find competitors and set the price" - then with the sources in the preview; never on your own. Without one: ask, the product stays a draft'],
            'quantity'         => ['data', self::ASK, 'only from the user (a value, a stock list); never on your own; 0 for a draft'],
            'manufacturer_id'  => ['data', self::FILL, 'an existing brand (list it with sql_query on manufacturer); create a missing brand first'],
            'status'           => ['data', self::ASK, 'a draft (false) unless the user says to enable it; enable only with a photo'],
            'weight'           => ['data', self::SKIP, 'only from the user\'s data'],
            'categories'       => ['list', self::FILL, 'the categories similar products are in (get_product of a neighbour)'],
            'image'            => ['image', self::FILL, 'main photo: import_image with attach or set_images after the page exists; ask where to take photos if the user gave no source'],
            'gallery'          => ['gallery', self::ASK, 'more photos: gallery in save_content, or set_images'],
            'attributes'       => ['attributes', self::FILL, 'like similar products (get_product of a neighbour), with bulk_update_products set.attributes'],
        ],
        'category' => [
            'name'             => ['texts', self::FILL, 'short, as in the menu'],
            'description'      => ['texts', self::FILL, 'what the category holds and how to choose; h2-h3, no h1'],
            'meta_title'       => ['texts', self::FILL, '30-60 characters, key phrase first'],
            'meta_description' => ['texts', self::FILL, '120-160 characters'],
            'meta_keyword'     => ['texts', self::FILL, 'a few key phrases, comma-separated'],
            'meta_h1'          => ['texts', self::FILL, 'the heading shown on the page (H1), it may differ from meta_title'],
            'seo_keyword'      => ['seo', self::FILL, 'made from the name when left out'],
            'parent_id'        => ['data', self::ASK, 'where in the category tree (0 = top level); suggest one'],
            'image'            => ['data', self::ASK, 'category picture (some themes show it)'],
            'top'              => ['data', self::ASK, 'shown in the top menu (top-level categories only)'],
            'status'           => ['data', self::ASK, 'a draft (false) unless the user says to enable it'],
            'sort_order'       => ['data', self::SKIP, 'position among its siblings'],
        ],
        'manufacturer' => [
            'name'             => ['data', self::FILL, 'the brand name as the brand writes it'],
            'description'      => ['texts', self::FILL, 'about the brand and its best-known products; h2-h3, no h1'],
            'meta_title'       => ['texts', self::FILL, '30-60 characters'],
            'meta_description' => ['texts', self::FILL, '120-160 characters'],
            'meta_keyword'     => ['texts', self::FILL, 'a few key phrases, comma-separated'],
            'meta_h1'          => ['texts', self::FILL, 'the heading shown on the brand page (H1)'],
            'seo_keyword'      => ['seo', self::FILL, 'made from the name when left out'],
            'image'            => ['data', self::ASK, 'brand logo'],
            'sort_order'       => ['data', self::SKIP, 'position in the brand list'],
        ],
        'article' => [
            'name'             => ['texts', self::FILL, 'the post title'],
            'description'      => ['texts', self::FILL, 'the post: useful, h2-h3, no h1, links to fitting products'],
            'meta_title'       => ['texts', self::FILL, '30-60 characters'],
            'meta_description' => ['texts', self::FILL, '120-160 characters'],
            'meta_keyword'     => ['texts', self::FILL, 'a few key phrases, comma-separated'],
            'meta_h1'          => ['texts', self::FILL, 'the heading shown on the post (H1)'],
            'tag'              => ['texts', self::FILL, 'comma-separated tags'],
            'seo_keyword'      => ['seo', self::FILL, 'made from the name when left out'],
            'categories'       => ['list', self::FILL, 'the blog categories (topic) of the post; the first is the main one'],
            'image'            => ['image', self::ASK, 'the post picture'],
            'date_added'       => ['data', self::ASK, 'publication date, YYYY-MM-DD (an earlier date is allowed)'],
            'date_available'   => ['data', self::ASK, 'shown from this date, YYYY-MM-DD'],
            'status'           => ['data', self::ASK, 'a draft (false) unless the user says to publish'],
        ],
        'topic' => [
            'name'             => ['texts', self::FILL, 'short, as in the blog menu'],
            'description'      => ['texts', self::FILL, 'what the category is about; h2-h3, no h1'],
            'meta_title'       => ['texts', self::FILL, '30-60 characters'],
            'meta_description' => ['texts', self::FILL, '120-160 characters'],
            'meta_h1'          => ['texts', self::FILL, 'the heading shown on the category page (H1)'],
            'seo_keyword'      => ['seo', self::FILL, 'made from the name when left out'],
            'parent_id'        => ['data', self::ASK, 'parent blog category (0 = top level)'],
            'status'           => ['data', self::ASK, 'a draft (false) unless the user says to publish'],
        ],
    ];

    /**
     * The fields of this store for one page type: rule, how to set it, hint. Columns of the description table the
     * catalog does not know (description3, a theme's own fields) are listed too, as "ask".
     * @return array<string, array{rule:string, set:string, hint:string}>
     */
    public static function fields(Context $c, string $entity): array {
        $d = ContentEntities::def($c, $entity);
        $catalog = self::CATALOG[$entity] ?? null;

        if ($d === null || $catalog === null) {
            return [];
        }

        $out = [];

        foreach ($catalog as $field => [$where, $rule, $hint]) {
            if (self::present($c, $d, $field, $where) && self::writable($c, $d, $field, $where)) {
                $out[$field] = ['rule' => $rule, 'set' => self::how($field, $where), 'hint' => $hint];
            }
        }

        foreach ($d['texts'] as $col) {
            if (!isset($out[$col]) && !isset($catalog[$col]) && self::writable($c, $d, $col, 'texts')) {
                $out[$col] = ['rule' => self::ASK, 'set' => 'texts.<language>.' . $col, 'hint' => 'a field of this store\'s theme or a module: ask the user what goes there, or leave it empty'];
            }
        }

        return $out;
    }

    /**
     * Fields with the rule fill or ask that are empty on a page: after a save, or planned in a preview.
     * @param array $row     the page's column values (current row merged with the planned data)
     * @param array $texts   language_id => column => text (current texts merged with the planned ones)
     * @param array $langs   language_id => {code, status}
     * @param int[]|null $categories planned categories, null = as stored
     * @return string[] e.g. ["meta_h1 (uk-ua, ru-ru)", "sku", "image"]
     */
    public static function empty(Context $c, array $d, int $id, array $row, array $texts, array $langs, ?array $categories = null, ?array $gallery = null): array {
        $out = [];

        foreach (self::fields($c, $d['entity']) as $field => $f) {
            if ($f['rule'] === self::SKIP || $field === 'seo_keyword' || $field === 'status' || $field === 'quantity') {
                continue; // SEO URLs are made from the name; a draft status and zero stock are legitimate values
            }

            if (strpos($f['set'], 'texts.') === 0) {
                $missing = [];

                foreach ($langs as $lid => $l) {
                    if (!empty($l['status']) && trim(strip_tags((string)($texts[$lid][$field] ?? ''))) === '') {
                        $missing[] = $l['code'];
                    }
                }

                if ($missing) {
                    $out[] = $field . ' (' . implode(', ', $missing) . ')';
                }

                continue;
            }

            if ($f['set'] === 'categories') {
                $link = \OcMcp\Tools\SaveContent::categoryLink($d);
                $n = $categories !== null ? count($categories) : ($id > 0 && $link ? (int)$c->db->value("SELECT COUNT(*) AS n FROM " . $c->db->t($link['table']) . " WHERE `" . $d['id'] . "` = " . $id, 'n') : 0);
            } elseif ($f['set'] === 'gallery') {
                $n = $gallery !== null ? count($gallery) : ($id > 0 ? (int)$c->db->value("SELECT COUNT(*) AS n FROM " . $c->db->t('product_image') . " WHERE `product_id` = " . $id, 'n') : 0);
            } elseif ($f['set'] === 'bulk_update_products set.attributes') {
                $n = $id > 0 ? (int)$c->db->value("SELECT COUNT(*) AS n FROM " . $c->db->t('product_attribute') . " WHERE `product_id` = " . $id, 'n') : 0;
            } elseif ($field === 'image' && ($d['image_in'] ?? 'main') === 'desc') {
                $first = reset($texts);
                $n = is_array($first) && trim((string)($first['image'] ?? '')) !== ''; // OpenCart 4.1 blog: the picture is a text column
            } else {
                $v = $row[$field] ?? '';
                $n = is_numeric($v) ? (float)$v != 0 : trim((string)$v) !== '';
            }

            if (!$n) {
                $out[] = $field;
            }
        }

        return $out;
    }

    /** A loud warning when an enabled product has no photo: visitors see an empty card. */
    public static function exposed(array $d, array $row): ?string {
        if ($d['entity'] === 'product' && !empty($row['status']) && trim((string)($row['image'] ?? '')) === '') {
            return 'ENABLED WITHOUT A PHOTO: visitors see this product with an empty picture. Add the photo or disable it (status false) until there is one.';
        }

        return null;
    }



    /** May the AI write this field (the Access tab)? A closed one is no task of its. */
    private static function writable(Context $c, array $d, string $field, string $where): bool {
        $p = $c->policy();

        switch ($where) {
            case 'texts':
                return $d['desc'] !== null && $p->canWrite($d['desc'], [$field]);
            case 'data':
                return $p->canWrite($d['table'], [$field]);
            case 'image':
                return $p->canWrite(($d['image_in'] ?? 'main') === 'desc' && $d['desc'] ? $d['desc'] : $d['table'], ['image']);
            case 'gallery':
                return $p->canWrite('product_image');
            case 'list':
                return $p->canWrite(\OcMcp\Tools\SaveContent::categoryLink($d)['table']);
            case 'attributes':
                return $p->canWrite('product_attribute');
            case 'seo':
                return $p->canWrite($c->hasTable('seo_url') ? 'seo_url' : 'url_alias', ['keyword']);
        }

        return false;
    }

    private static function present(Context $c, array $d, string $field, string $where): bool {
        switch ($where) {
            case 'texts':
                return in_array($field, $d['texts'], true);
            case 'data':
                return in_array($field, $d['data'], true);
            case 'image':
                return $c->hasColumn(($d['image_in'] ?? 'main') === 'desc' && $d['desc'] ? $d['desc'] : $d['table'], 'image');
            case 'gallery':
                return $c->hasTable('product_image');
            case 'list':
                return \OcMcp\Tools\SaveContent::categoryLink($d) !== null && $c->hasTable(\OcMcp\Tools\SaveContent::categoryLink($d)['table']);
            case 'attributes':
                // only stores that use attributes: an empty attribute table means the owner does not
                return $c->hasTable('product_attribute') && $c->db->row("SELECT 1 AS x FROM " . $c->db->t('product_attribute') . " LIMIT 1") !== null;
            case 'seo':
                return true;
        }

        return false;
    }

    private static function how(string $field, string $where): string {
        switch ($where) {
            case 'texts':
                return 'texts.<language>.' . $field;
            case 'data':
                return 'data.' . $field;
            case 'image':
                return 'data.image or set_images main';
            case 'gallery':
                return 'gallery';
            case 'list':
                return 'categories';
            case 'attributes':
                return 'bulk_update_products set.attributes';
            case 'seo':
                return 'texts.<language>.seo_keyword';
        }

        return $field;
    }
}
