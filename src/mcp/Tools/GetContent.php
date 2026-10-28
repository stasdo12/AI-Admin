<?php
namespace OcMcp\Tools;

use OcMcp\ContentEntities;
use OcMcp\ContentPolicy;
use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

/** A store page with all its texts in every language, SEO keywords, links and images: what save_content edits. */
final class GetContent extends Tool {
    public function name(): string {
        return 'get_content';
    }

    public function describe(): array {
        return [
            'title'       => 'Get a page with all its texts',
            'description' => 'Read a product, category, information page, manufacturer, blog article or blog topic with its texts in every store language (full HTML of descriptions), '
                . 'SEO keywords, storefront links and images. Use it before save_content to see what is already written.',
            'inputSchema' => self::schema([
                'entity' => self::prop('string', 'What to read.', ['enum' => array_keys(ContentEntities::DEF)]),
                'id'     => ['type' => 'integer'],
                'ids'    => self::prop('array', 'Several pages at once (up to ' . self::MAX_IDS . ').', ['items' => ['type' => 'integer']]),
            ], ['entity']),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    const MAX_IDS = 20;
    const MAX_BYTES = 120000; // full descriptions of 20 pages in two languages: stop before they fill the AI's context

    public function run(array $a, Context $c): ToolResult {
        if (isset($a['ids'])) {
            return $this->many($a, $c);
        }

        $entity = $this->enum($a, 'entity', array_keys(ContentEntities::DEF), 'product');
        $this->need($a, 'id');
        $id = (int)$this->int($a, 'id');
        $d = ContentEntities::def($c, $entity);

        if ($d === null) {
            return ToolResult::error(ContentEntities::missing($c, $entity));
        }

        $policy = $c->policy();

        foreach (array_filter([$d['table'], $d['desc']]) as $t) {
            if (!$policy->canRead($t)) {
                return \OcMcp\AccessDenied::result(\OcMcp\AccessDenied::table($t, 'read ' . $entity . ' pages', $policy->level($t), 'read'));
            }
        }

        $db = $c->db;
        $row = $db->row("SELECT * FROM " . $db->t($d['table']) . " WHERE `" . $d['id'] . "` = " . $id);

        if ($row === null) {
            return ToolResult::error(ucfirst($entity) . " $id not found.");
        }

        $row = $policy->stripHiddenColumns([$row], [$d['table']])[0];
        $out = [$d['id'] => $id, 'entity' => $entity, 'data' => array_intersect_key($row, array_flip(array_merge($d['data'], ['date_added', 'date_modified'])))];
        $langs = ContentEntities::languages($db);
        $keywords = $policy->canRead('seo_url') ? ContentEntities::keywords($c, $d, $id) : [];
        $texts = [];

        if ($d['desc']) {
            $rows = $policy->stripHiddenColumns($db->rows("SELECT * FROM " . $db->t($d['desc']) . " WHERE `" . $d['id'] . "` = " . $id), [$d['desc']]);

            foreach ($rows as $r) {
                $lid = (int)$r['language_id'];
                unset($r[$d['id']], $r['language_id']);
                $texts[$langs[$lid]['code'] ?? (string)$lid] = array_map(function ($v) { return ContentPolicy::unstore((string)$v); }, $r);
            }
        }

        foreach ($langs as $lid => $info) {
            $texts[$info['code']]['seo_keyword'] = $keywords[$lid] ?? '';
            $texts[$info['code']]['url'] = $policy->canRead('seo_url') ? ContentEntities::url($c, $d, $id, $lid) : ''; // the SEO keyword is in the pretty URL: closed SEO URLs, no URL
        }

        $out['texts'] = $texts;

        if ($entity === 'product') {
            $out['categories'] = array_map('intval', array_column($db->rows("SELECT `category_id` FROM " . $db->t('product_to_category') . " WHERE `product_id` = " . $id), 'category_id'));

            if ($policy->canRead('product_image')) {
                $out['images'] = $db->rows("SELECT `image`, `sort_order` FROM " . $db->t('product_image') . " WHERE `product_id` = " . $id . " ORDER BY `sort_order`");
            }
        }

        if ($entity === 'category') {
            $out['path'] = ContentEntities::categoryPath($db, $id);
        }

        if ($entity !== 'product' && ($link = \OcMcp\Tools\SaveContent::categoryLink($d)) !== null) {
            $out['categories'] = array_map('intval', array_column($db->rows("SELECT `" . $link['col'] . "` FROM " . $db->t($link['table']) . " WHERE `" . $d['id'] . "` = " . $id), $link['col']));
        }

        if (!empty($d['profile'])) {
            $out['blog'] = $d['label'] ?? $d['profile']; // which blog of the store this is
        }

        return ToolResult::data($out);
    }

    /** Many pages in one call (reading 50 articles before rewriting or interlinking them took 50 calls). */
    private function many(array $a, Context $c): ToolResult {
        $ids = is_array($a['ids']) ? array_values(array_unique(array_filter(array_map('intval', $a['ids'])))) : [];

        if (!$ids || count($ids) > self::MAX_IDS) {
            return ToolResult::error('ids: 1 to ' . self::MAX_IDS . ' pages per call; more in the next call.');
        }

        $pages = [];
        $missing = [];
        $bytes = 0;

        foreach ($ids as $n => $id) {
            $r = $this->run(['entity' => $a['entity'] ?? 'product', 'id' => $id], $c);

            if ($r->isError) {
                if (!is_array($r->structured) || ($r->structured['error'] ?? '') !== 'access_denied') {
                    $missing[] = $id; // not found: the others still come
                    continue;
                }

                return $r; // a closed table answers for all of them
            }

            $bytes += strlen((string)json_encode($r->structured, JSON_UNESCAPED_UNICODE));

            if ($bytes > self::MAX_BYTES && $pages) {
                $left = array_slice($ids, $n);

                return ToolResult::data(['pages' => $pages, 'not_found' => $missing, 'not_read_yet' => $left], count($pages) . ' page(s); ' . count($left) . ' not read yet (the answer would be too long): ask for them in the next call.');
            }

            $pages[] = $r->structured;
        }

        return ToolResult::data(['pages' => $pages] + ($missing ? ['not_found' => $missing] : []), count($pages) . ' page(s)' . ($missing ? ', not found: ' . implode(', ', $missing) : '') . '.');
    }
}
