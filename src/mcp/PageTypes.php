<?php
namespace OcMcp;

/**
 * Blog modules the module does not ship a profile for. The store owner connects one in the settings: the module
 * proposes a profile from the tables it finds (a table, its _description with language_id and a name, its _to_store,
 * a link to categories), the owner confirms it, and from then on the blog tools write there. Until then such a blog
 * is read-only for the AI and every refusal says how to connect it.
 */
final class PageTypes {
    const SETTING = 'module_mcp_server_page_types';
    const ENTITIES = ['article', 'topic'];

    /** The owner's confirmed profiles: entity => profile (only well-formed ones). */
    public static function owner(Context $c): array {
        return self::normalize($c->config(self::SETTING));
    }

    public static function normalize($raw): array {
        if (is_string($raw)) {
            $raw = json_decode(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'), true);
        }

        $out = [];
        $name = '/^[a-z0-9_]{1,64}$/';

        foreach (is_array($raw) ? $raw : [] as $entity => $p) {
            if (!in_array($entity, self::ENTITIES, true) || !is_array($p)) {
                continue;
            }

            if (!preg_match($name, (string)($p['table'] ?? '')) || !preg_match($name, (string)($p['id'] ?? '')) || !preg_match($name, (string)($p['title'] ?? 'name'))) {
                continue;
            }

            $q = ['label' => mb_substr(strip_tags((string)($p['label'] ?? 'Blog')), 0, 60), 'table' => (string)$p['table'], 'id' => (string)$p['id'], 'title' => (string)($p['title'] ?? 'name'),
                  'desc' => preg_match($name, (string)($p['desc'] ?? '')) ? (string)$p['desc'] : '', 'store' => preg_match($name, (string)($p['store'] ?? '')) ? (string)$p['store'] : '',
                  'data' => array_values(array_filter((array)($p['data'] ?? []), function ($x) use ($name) { return is_string($x) && preg_match($name, $x); })),
                  'route' => preg_match('~^[a-z0-9_/|.{}]{1,128}$~', (string)($p['route'] ?? '')) ? (string)$p['route'] : '',
                  'image_in' => ($p['image_in'] ?? 'main') === 'desc' ? 'desc' : 'main'];

            if (preg_match($name, (string)($p['seo'] ?? ''))) {
                $q['seo'] = (string)$p['seo'];
            }

            if (isset($p['cats']) && is_array($p['cats']) && preg_match($name, (string)($p['cats']['table'] ?? '')) && preg_match($name, (string)($p['cats']['col'] ?? '')) && preg_match($name, (string)($p['cats']['ref'] ?? ''))) {
                $q['cats'] = ['table' => (string)$p['cats']['table'], 'col' => (string)$p['cats']['col'], 'ref' => (string)$p['cats']['ref']]
                    + (preg_match($name, (string)($p['cats']['main'] ?? '')) ? ['main' => (string)$p['cats']['main']] : []);
            }

            if (preg_match($name, (string)($p['path'] ?? ''))) {
                $q['path'] = (string)$p['path'];
            }

            $q['sign'] = [$q['table'] => [$q['id']]] + ($q['desc'] !== '' ? [$q['desc'] => [$q['id'], 'language_id', $q['title']]] : []);
            $out[$entity] = $q;
        }

        return $out;
    }

    /**
     * Blog-like page types of modules no shipped profile covers: a table named after blog / article / news / post with
     * a _description table (language_id and a name or title). The proposal fills what the tables show; the owner checks
     * the storefront route.
     * @return array<int, array> proposals: entity => profile, plus 'tables'
     */
    public static function discover(Db $db): array {
        $prefix = $db->prefix();
        $all = [];

        foreach ($db->rows("SHOW TABLES LIKE '" . $db->esc(addcslashes($prefix, '_%')) . "%'") as $r) {
            $t = substr((string)reset($r), strlen($prefix));
            $all[$t] = true;
        }

        $shipped = [];

        foreach (ContentEntities::PROFILES as $profiles) {
            foreach ($profiles as $p) {
                $shipped[$p['table']] = true;
            }
        }

        $cols = function (string $t) use ($db) { return array_map(function ($r) { return $r['Field']; }, $db->rows("SHOW COLUMNS FROM " . $db->t($t))); };
        $found = [];

        foreach (array_keys($all) as $t) {
            if (isset($shipped[$t]) || !preg_match('/(blog|article|news|post)/', $t) || preg_match('/_(description|to_store|to_layout|to_category|path|image|related|comments?|review|rating|product)$/', $t) || !isset($all[$t . '_description'])) {
                continue;
            }

            $main = $cols($t);
            $desc = $cols($t . '_description');
            $id = $main[0] ?? '';

            if ($id === '' || !in_array($id, $desc, true) || !in_array('language_id', $desc, true)) {
                continue;
            }

            $title = in_array('name', $desc, true) ? 'name' : (in_array('title', $desc, true) ? 'title' : '');

            if ($title === '') {
                continue;
            }

            $found[$t] = ['table' => $t, 'id' => $id, 'title' => $title, 'desc' => $t . '_description', 'store' => isset($all[$t . '_to_store']) ? $t . '_to_store' : '',
                          'data' => array_values(array_intersect($main, ['image', 'status', 'sort_order', 'date_added', 'date_available', 'parent_id', 'author', 'noindex'])),
                          'image_in' => in_array('image', $main, true) ? 'main' : 'desc', 'has_parent' => in_array('parent_id', $main, true)];
        }

        // a category type (with parent_id, or named *category*) and an article type linked to it by <article>_to_category
        $out = [];

        foreach ($found as $t => $p) {
            $isCat = $p['has_parent'] || strpos($t, 'categor') !== false;
            unset($p['has_parent']);
            $p['label'] = $t;

            if ($isCat) {
                if (isset($all[$t . '_path'])) {
                    $p['path'] = $t . '_path';
                }

                $out['topic'][] = $p;
            } else {
                foreach ($found as $ct => $cp) {
                    if (($cp['has_parent'] || strpos($ct, 'categor') !== false) && isset($all[$t . '_to_category'])) {
                        $link = $cols($t . '_to_category');
                        $col = in_array($cp['id'], $link, true) ? $cp['id'] : (string)(array_values(array_diff($link, [$p['id'], 'main_category']))[0] ?? '');

                        if ($col !== '') {
                            $p['cats'] = ['table' => $t . '_to_category', 'col' => $col, 'ref' => $ct] + (in_array('main_category', $link, true) ? ['main' => 'main_category'] : []);
                        }
                    }
                }

                $out['article'][] = $p;
            }
        }

        // SEO keys: the store's own seo_url rows tell which key a module uses (Oct Blog: oct_blog_category_id=1)
        $keys = [];

        try {
            $col = $db->rows("SHOW COLUMNS FROM " . $db->t('seo_url') . " LIKE 'key'") ? '`key`' : "SUBSTRING_INDEX(`query`, '=', 1)";

            foreach ($db->rows("SELECT DISTINCT " . $col . " AS k FROM " . $db->t('seo_url') . " WHERE " . $col . " LIKE '%blog%' OR " . $col . " LIKE '%article%' OR " . $col . " LIKE '%news%' LIMIT 50") as $r) {
                $keys[] = (string)($r['k'] ?? '');
            }
        } catch (\Throwable $e) {
        }

        foreach (['article', 'topic'] as $entity) {
            foreach ($out[$entity] ?? [] as $i => $p) {
                $guess = '';

                foreach ($keys as $k) {
                    if (in_array($k, ['article_id', 'blog_category_id', 'topic_id'], true)) {
                        continue; // keys of the shipped blogs
                    }

                    if (($entity === 'topic') === (strpos($k, 'categor') !== false)) {
                        $guess = $k;
                    }
                }

                if ($guess === '' && $entity === 'article') {
                    foreach ($keys as $k) {
                        if (strpos($k, 'categor') !== false && !in_array($k, ['blog_category_id'], true)) {
                            $guess = str_replace('category', 'article', $k); // oct_blog_category_id -> oct_blog_article_id
                        }
                    }
                }

                $out[$entity][$i]['seo'] = $guess !== '' ? $guess : $p['id'];
            }
        }

        return $out;
    }

    /**
     * The storefront route of a found blog's posts or categories, from the module's own routes (layout, SeoPro,
     * extension): the one named after the page type; when that does not decide, the one an existing published page of
     * the blog opens with (a few requests, remembered in the store cache for a day). '' = unknown.
     */
    public static function pickRoute(array $routes, string $entity, ?Context $c = null, array $p = []): string {
        $word = $entity === 'topic' ? '/(categor|topic|rubric|section)/i' : '/(article|post|news|entry|story)/i';
        $other = $entity === 'topic' ? '/(article|post|news|entry|story)/i' : '/(categor|topic|rubric|section)/i';
        $named = array_values(array_filter($routes, function ($r) use ($word, $other) { return preg_match($word, $r) && !preg_match($other, $r); }));

        if (count($named) === 1) {
            return $named[0];
        }

        $cands = array_slice($named ?: $routes, 0, 4);

        if (!$cands || $c === null || empty($p['table']) || !function_exists('curl_init')) {
            return count($cands) === 1 ? $cands[0] : '';
        }

        $key = 'mcp_blog_route.' . md5($p['table'] . '|' . $entity . '|' . implode(',', $cands));

        if ($c->cache && method_exists($c->cache, 'get')) {
            try {
                $hit = $c->cache->get($key);

                if (is_string($hit)) {
                    return $hit;
                }
            } catch (\Throwable $e) {
            }
        }

        $found = '';
        $asked = false; // the storefront answered at least once: a failed probe then means the route is wrong

        try {
            $page = $c->db->row("SELECT m.`" . $p['id'] . "` AS id, d.`" . $p['title'] . "` AS name FROM " . $c->db->t($p['table']) . " m JOIN " . $c->db->t($p['desc']) . " d ON d.`" . $p['id'] . "` = m.`" . $p['id'] . "`"
                . (in_array('status', (array)$p['data'], true) ? " WHERE m.`status` = 1" : '') . " ORDER BY m.`" . $p['id'] . "` DESC LIMIT 1");

            foreach ($page ? $cands : [] as $route) {
                $url = rtrim($c->storeUrl, '/') . '/index.php?route=' . rawurlencode($route) . '&' . rawurlencode((string)($p['seo'] ?? $p['id'])) . '=' . (int)$page['id'];
                $r = \OcMcp\Tools\SaveContent::storefront($url, (string)$page['name'], ['entity' => $entity]);

                $asked = $asked || !empty($r['http']);

                if (!empty($r['ok']) && (int)($r['http'] ?? 0) === 200) {
                    $found = $route;
                    break;
                }
            }
        } catch (\Throwable $e) {
        }

        if (!$asked) {
            return count($cands) === 1 ? $cands[0] : ''; // nothing published to try, or the storefront is out of reach: not remembered
        }

        if ($c->cache && method_exists($c->cache, 'set')) {
            try {
                $c->cache->set($key, $found);
            } catch (\Throwable $e) {
            }
        }

        return $found;
    }

    /** The shipped blog profiles this store has (for the settings page, which has no Context): entity => label. */
    public static function shipped(Db $db): array {
        return array_map(function ($p) { return $p['label']; }, self::shippedProfiles($db));
    }

    /** entity => the first shipped profile whose sign fits this store, checked with the database alone. */
    public static function shippedProfiles(Db $db): array {
        $has = function (string $t, array $cols) use ($db) {
            if (!$db->tableExists($t)) {
                return false;
            }

            $all = array_map(function ($r) { return $r['Field']; }, $db->rows("SHOW COLUMNS FROM " . $db->t($t)));

            return !array_diff($cols, $all);
        };
        $out = [];

        foreach (ContentEntities::PROFILES as $entity => $profiles) {
            foreach ($profiles as $p) {
                $ok = $has($p['table'], []);

                foreach ($p['sign'] as $t => $cols) {
                    $ok = $ok && $has($t, $cols);
                }

                if ($ok) {
                    $out[$entity] = $p;
                    break;
                }
            }
        }

        return $out;
    }
}
