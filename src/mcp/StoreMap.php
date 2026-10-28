<?php
namespace OcMcp;

/**
 * What this store really runs, read from the database alone (SELECT only): the theme, the enabled extensions and
 * modifications, the storefront routes and SEO keys no stock OpenCart has, and every blog it holds with the signs of
 * which one the storefront shows. Two blog modules often leave tables side by side; the tables alone cannot tell
 * which one visitors see, these signs can.
 */
final class StoreMap {
    const CORE_ROUTES = ['common/', 'product/', 'information/', 'account/', 'checkout/', 'affiliate/', 'error/'];
    const CORE_SEO = ['product_id', 'category_id', 'path', 'manufacturer_id', 'information_id', 'route', 'language'];
    const CAP = 30;
    /** A blog with at least this many signs (posts, recent posts, routes, SEO URLs, enabled module ...) is in use. */
    const LIVE_SCORE = 2;
    /** Extension types every store has the same of: listed they only fill the answer. */
    const QUIET_TYPES = ['dashboard', 'report', 'total', 'currency', 'language'];

    /** Signs of the shipped blogs: route prefixes, exact SEO keys, setting codes. */
    const LIVE = [
        'oc41'    => ['route' => ['cms/blog'], 'seo' => ['article_id', 'topic_id'], 'setting' => []],
        'ocstore' => ['route' => ['blog/'], 'seo' => ['article_id', 'blog_category_id'], 'setting' => ['configblog']],
    ];

    /** The map of the store of this request (built once per request). */
    public static function of(Context $c): array {
        return $c->memo('store_map', function () use ($c) { return self::build($c->db, PageTypes::owner($c)); });
    }

    public static function build(Db $db, array $owner = []): array {
        $prefix = $db->prefix();
        $tables = [];

        foreach ($db->rows("SHOW TABLES LIKE '" . $db->esc(addcslashes($prefix, '_%')) . "%'") as $r) {
            $tables[substr((string)reset($r), strlen($prefix))] = true;
        }

        $try = function (callable $fn, $fallback) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                return $fallback; // a table of another shape: that part of the map stays empty
            }
        };
        $cols = function (string $t) use ($db, $tables, $try): array {
            return isset($tables[$t]) ? $try(function () use ($db, $t) { return array_map(function ($r) { return $r['Field']; }, $db->rows("SHOW COLUMNS FROM " . $db->t($t))); }, []) : [];
        };

        // settings: which codes exist and which <type>_<code>_status / <code>_status are on (values other than the theme are not read)
        $on = [];
        $codes = [];
        $theme = '';

        if (isset($tables['setting'])) {
            foreach ($try(function () use ($db) {
                return $db->rows("SELECT `code`, `key`, `value` FROM " . $db->t('setting') . " WHERE `store_id` = 0 AND (`key` LIKE '%\\_status' OR `key` = 'config_theme' OR `key` LIKE 'theme\\_%\\_directory')");
            }, []) as $r) {
                if ($r['key'] === 'config_theme') {
                    $theme = (string)$r['value'];
                } elseif (substr($r['key'], -7) === '_status') {
                    $on[$r['key']] = (string)$r['value'] === '1';
                }
            }

            foreach ($try(function () use ($db) { return $db->rows("SELECT DISTINCT `code` FROM " . $db->t('setting') . " WHERE `store_id` = 0 LIMIT 500"); }, []) as $r) {
                $codes[] = (string)$r['code'];
            }
        }

        $extensions = [];
        $off = 0;

        if (isset($tables['extension'])) {
            foreach ($try(function () use ($db) { return $db->rows("SELECT `type`, `code` FROM " . $db->t('extension') . " ORDER BY `type`, `code`"); }, []) as $r) {
                $type = (string)$r['type'];
                $code = (string)$r['code'];

                if (in_array($type, self::QUIET_TYPES, true)) {
                    continue;
                }

                if (!empty($on[$type . '_' . $code . '_status']) || !empty($on[$code . '_status']) || $type === 'theme' && $code === $theme) {
                    $extensions[$type][] = $code;
                } else {
                    $off++;
                }
            }
        }

        $mods = [];
        $modsOff = 0;

        if (isset($tables['modification'])) {
            foreach ($try(function () use ($db) { return $db->rows("SELECT `name`, `code`, `status` FROM " . $db->t('modification') . " ORDER BY `name`"); }, []) as $r) {
                if ((int)$r['status']) {
                    $mods[] = ['name' => strip_tags((string)$r['name']), 'code' => (string)$r['code']];
                } else {
                    $modsOff++;
                }
            }
        }

        $routes = [];

        if (isset($tables['layout_route'])) {
            foreach ($try(function () use ($db) { return $db->rows("SELECT DISTINCT `route` FROM " . $db->t('layout_route') . " WHERE `route` <> '' ORDER BY `route` LIMIT 300"); }, []) as $r) {
                $route = (string)$r['route'];

                if (!self::startsWith($route, self::CORE_ROUTES)) {
                    $routes[] = $route;
                }
            }
        }

        $seo = [];
        $seoRoutes = []; // SeoPro keeps a module's route as a query of its own: octemplates/blog/oct_blogarticle

        if (isset($tables['seo_url'])) {
            $key = in_array('key', $cols('seo_url'), true) ? '`key`' : "SUBSTRING_INDEX(`query`, '=', 1)";

            foreach ($try(function () use ($db, $key) { return $db->rows("SELECT " . $key . " AS k, COUNT(*) AS n FROM " . $db->t('seo_url') . " GROUP BY k ORDER BY n DESC LIMIT 300"); }, []) as $r) {
                $k = (string)$r['k'];

                if ($k !== '' && strpos($k, '/') === false && !in_array($k, self::CORE_SEO, true)) {
                    $seo[$k] = (int)$r['n'];
                } elseif (strpos($k, '/') !== false && !self::startsWith($k, self::CORE_ROUTES)) {
                    $seoRoutes[] = $k;
                }
            }
        }

        $map = array_filter([
            'theme'         => $theme,
            'extensions'    => array_map(function ($l) { return array_slice($l, 0, self::CAP); }, $extensions),
            'extensions_off'=> $off,
            'modifications' => array_slice(array_map(function ($m) { return $m['name']; }, $mods), 0, self::CAP),
            'modifications_off' => $modsOff,
            'custom_routes' => array_slice($routes, 0, self::CAP),
            'custom_seo_keys' => array_slice($seo, 0, self::CAP, true),
        ], function ($v) { return $v !== '' && $v !== [] && $v !== 0; });

        $map['blogs'] = self::blogs($db, $tables, $cols, $try, compact('on', 'codes', 'extensions', 'mods', 'routes', 'seo', 'seoRoutes'), $owner);

        if (!$map['blogs']) {
            unset($map['blogs']);
        }

        return $map;
    }

    /**
     * Every blog this store holds: the shipped ones whose columns fit, the owner's connected one, the tables of other
     * blog modules. Each with its posts and the signs that the storefront shows it; 'score' counts them.
     */
    private static function blogs(Db $db, array $tables, callable $cols, callable $try, array $s, array $owner): array {
        $cands = [];

        foreach (ContentEntities::PROFILES['article'] as $name => $p) {
            $ok = isset($tables[$p['table']]);

            foreach ($p['sign'] as $t => $need) {
                $ok = $ok && !array_diff($need, $cols($t));
            }

            if ($ok) {
                $cands[$p['table'] . ':' . $name] = ['label' => $p['label'], 'profile' => $name, 'table' => $p['table'], 'id' => $p['id']] + self::LIVE[$name];
            }
        }

        $others = [];

        if (isset($owner['article'])) {
            $others[] = $owner['article'] + ['profile' => 'owner'];
        }

        foreach ($try(function () use ($db) { return PageTypes::discover($db); }, [])['article'] ?? [] as $p) {
            $others[] = $p + ['profile' => 'found'];
        }

        foreach ($others as $p) {
            $k = $p['table'] . ':other';

            if (isset($cands[$k]) || isset($tables[$p['table']]) === false) {
                continue;
            }

            // oct_blogarticle -> oct_blog: the module's own name, found again in its routes, keys, settings and extensions
            $token = preg_replace('/_?(articles?|posts?|news|entry|entries)$/', '', (string)$p['table']);
            $cands[$k] = ['label' => $p['profile'] === 'owner' ? (string)($p['label'] ?? $p['table']) : $p['table'], 'profile' => $p['profile'], 'table' => (string)$p['table'], 'id' => (string)$p['id'],
                          'token' => strlen($token) >= 3 ? $token : (string)$p['table']];
        }

        $out = [];

        foreach ($cands as $c) {
            $signs = [];
            $tc = $cols($c['table']);
            $row = $try(function () use ($db, $c, $tc) {
                return $db->row("SELECT COUNT(*) AS n" . (in_array('status', $tc, true) ? ", SUM(`status` = 1) AS e" : '') . (in_array('date_added', $tc, true) ? ", MAX(`date_added`) AS d" : '') . " FROM " . $db->t($c['table']));
            }, []);
            $posts = (int)($row['n'] ?? 0);
            $last = !empty($row['d']) && substr((string)$row['d'], 0, 4) !== '0000' ? substr((string)$row['d'], 0, 10) : '';

            if (isset($c['token'])) {
                $t = $c['token'];
                $has = function (string $v) use ($t) { return stripos($v, $t) !== false; };
                $routes = array_values(array_filter($s['routes'], $has));
                $seo = array_values(array_filter(array_keys($s['seo']), $has));
                $sets = array_values(array_filter($s['codes'], $has));
                $ext = [];

                foreach ($s['extensions'] as $type => $list) {
                    foreach ($list as $code) {
                        if ($has($code)) {
                            $ext[] = $type . '/' . $code;
                        }
                    }
                }

                $mods = array_values(array_map(function ($m) { return $m['name']; }, array_filter($s['mods'], function ($m) use ($has) { return $has($m['code']) || $has($m['name']); })));
                // where the module's pages may live on the storefront: its layout routes, its SeoPro routes, its extension's controller
                $cand = array_merge($routes, array_values(array_filter($s['seoRoutes'], $has)));

                foreach ($ext as $e) {
                    list($type, $code) = explode('/', $e, 2);
                    $cand[] = 'extension/' . $type . '/' . $code;
                }

                $c['routes'] = array_values(array_unique($cand));            } else {
                $routes = array_values(array_filter($s['routes'], function ($r) use ($c) { return self::startsWith($r, $c['route']); }));
                $seo = array_values(array_intersect(array_keys($s['seo']), $c['seo']));
                $sets = array_values(array_intersect($s['codes'], $c['setting']));
                $ext = $mods = [];
            }

            if ($routes) {
                $signs[] = 'layout routes ' . implode(', ', array_slice($routes, 0, 4));
            }

            if ($seo) {
                $signs[] = 'SEO URLs ' . implode(', ', array_map(function ($k) use ($s) { return $k . ' (' . $s['seo'][$k] . ')'; }, $seo));
            }

            if ($ext) {
                $signs[] = 'enabled extensions ' . implode(', ', array_slice($ext, 0, 4));
            }

            if ($mods) {
                $signs[] = 'enabled modifications ' . implode(', ', array_slice($mods, 0, 4));
            }

            if ($sets) {
                $signs[] = 'settings ' . implode(', ', array_slice($sets, 0, 4));
            }

            $recent = $last !== '' && strtotime($last) > time() - 365 * 86400;
            $out[] = array_filter(['label' => $c['label'], 'profile' => $c['profile'], 'table' => $c['table'], 'posts' => $posts, 'enabled' => isset($row['e']) ? (int)$row['e'] : null,
                                   'last_post' => $last, 'signs' => $signs, 'routes' => $c['routes'] ?? null, 'token' => $c['token'] ?? null], function ($v) { return $v !== null && $v !== '' && $v !== []; })
                     + ['score' => count($signs) + ($posts > 0 ? 1 : 0) + ($recent ? 1 : 0)];
        }

        usort($out, function ($a, $b) { return $b['score'] <=> $a['score']; });

        return $out;
    }

    /** The score of a blog profile in this store (0 when it shows no sign). */
    public static function score(Context $c, string $table, string $profile): int {
        foreach (self::of($c)['blogs'] ?? [] as $b) {
            if ($b['table'] === $table && ($b['profile'] === $profile || !isset(self::LIVE[$profile]) && !isset(self::LIVE[$b['profile']]))) {
                return (int)$b['score'];
            }
        }

        return 0;
    }

    /**
     * When the store holds another blog that looks used, a sentence saying where the page went and how to switch;
     * '' otherwise. $d = the definition the page was saved with.
     */
    public static function otherBlog(Context $c, array $d): string {
        $table = ($d['entity'] ?? '') === 'topic' ? (string)(ContentEntities::profile($c, 'article')['table'] ?? '') : (string)$d['table'];
        $here = null;
        $other = null;

        foreach (self::of($c)['blogs'] ?? [] as $b) {
            if ($b['table'] === $table && $here === null) {
                $here = $b;
            } elseif ($other === null && $b['score'] > 0) {
                $other = $b;
            }
        }

        if ($other === null) {
            return '';
        }

        return 'This store also has another blog: ' . $other['label'] . ' (' . (int)($other['posts'] ?? 0) . ' posts' . (!empty($other['last_post']) ? ', last ' . $other['last_post'] : '')
            . (!empty($other['signs']) ? '; ' . implode('; ', $other['signs']) : '') . '). This page went to ' . ($here['label'] ?? (string)($d['label'] ?? $table))
            . ' (' . (int)($here['posts'] ?? 0) . ' posts' . (!empty($here['last_post']) ? ', last ' . $here['last_post'] : '') . '). If the storefront shows the other one, tell the user:'
            . ' the store owner connects it under MCP Server → Settings → Page types, and the page can then be saved there.';
    }

    private static function startsWith(string $s, array $prefixes): bool {
        foreach ($prefixes as $p) {
            if ($p !== '' && strncmp($s, $p, strlen($p)) === 0) {
                return true;
            }
        }

        return false;
    }
}
