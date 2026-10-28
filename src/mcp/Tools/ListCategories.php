<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class ListCategories extends Tool {
    public function name(): string {
        return 'list_categories';
    }

    public function tables(): array {
        return ['category', 'category_description'];
    }

    public function fieldsKey(): ?string {
        return 'category_id';
    }

    public function describe(): array {
        return [
            'title'       => 'List categories',
            'description' => 'All categories as a flat list with full path (Parent > Child), parent_id, status and number of assigned products.',
            'inputSchema' => self::schema([
                'search'    => self::prop('string', 'Filter by name substring.'),
                'parent_id' => self::prop('integer', 'Only direct children of this category (0 = top level).'),
            ]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $db = $c->db;
        $L = (int)$c->languageId;

        $top = $c->hasColumn('category', 'top') ? "c.`top`" : "0 AS top";

        $rows = $db->rows("SELECT c.`category_id`, c.`parent_id`, c.`status`, c.`sort_order`, " . $top . ", cd.`name`"
            . " FROM " . $db->t('category') . " c"
            . " LEFT JOIN " . $db->t('category_description') . " cd ON cd.`category_id` = c.`category_id` AND cd.`language_id` = " . $L
            . " ORDER BY c.`parent_id`, c.`sort_order`, cd.`name`");

        $counts = [];

        foreach ($db->rows("SELECT `category_id`, COUNT(*) AS c FROM " . $db->t('product_to_category') . " GROUP BY `category_id`") as $r) {
            $counts[(int)$r['category_id']] = (int)$r['c'];
        }

        $byId = [];

        foreach ($rows as $r) {
            $byId[(int)$r['category_id']] = $r;
        }

        $pathOf = function (int $id) use (&$byId, &$pathOf): string {
            $names = [];
            $guard = 0;

            while ($id && isset($byId[$id]) && $guard++ < 20) {
                array_unshift($names, (string)$byId[$id]['name']);
                $id = (int)$byId[$id]['parent_id'];
            }

            return implode(' > ', $names);
        };

        $search = strtolower((string)$this->str($a, 'search', ''));
        $parent = $this->int($a, 'parent_id');
        $items = [];

        foreach ($rows as $r) {
            $id = (int)$r['category_id'];

            if ($parent !== null && (int)$r['parent_id'] !== $parent) {
                continue;
            }

            $name = (string)$r['name'];

            if ($search !== '' && strpos(strtolower($name), $search) === false) {
                continue;
            }

            $items[] = [
                'category_id'   => $id,
                'parent_id'     => (int)$r['parent_id'],
                'name'          => $name,
                'path'          => $pathOf($id),
                'status'        => (bool)$r['status'],
                'in_top_menu'   => (bool)$r['top'],
                'sort_order'    => (int)$r['sort_order'],
                'product_count' => $counts[$id] ?? 0,
            ];
        }

        usort($items, function ($x, $y) {
            return strcmp($x['path'], $y['path']);
        });

        return ToolResult::data(['total' => count($items), 'items' => $items]);
    }
}
