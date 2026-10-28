<?php
namespace OcMcp;

/**
 * Store pages the content tools create and edit: their tables, the fields the AI may set, SEO keywords and storefront
 * links, the same way the admin forms of OpenCart 3 / 4 store them.
 */
final class ContentEntities {
    const DEF = [
        'product'      => ['table' => 'product', 'id' => 'product_id', 'desc' => 'product_description', 'store' => 'product_to_store', 'title' => 'name',
                           'data' => ['model', 'sku', 'upc', 'ean', 'jan', 'isbn', 'mpn', 'location', 'quantity', 'stock_status_id', 'image', 'manufacturer_id', 'shipping',
                                      'price', 'points', 'tax_class_id', 'date_available', 'weight', 'weight_class_id', 'length', 'width', 'height', 'length_class_id',
                                      'subtract', 'minimum', 'sort_order', 'status', 'date_added', 'noindex']],
        'category'     => ['table' => 'category', 'id' => 'category_id', 'desc' => 'category_description', 'store' => 'category_to_store', 'title' => 'name',
                           'data' => ['image', 'parent_id', 'top', 'column', 'sort_order', 'status', 'noindex']],
        'information'  => ['table' => 'information', 'id' => 'information_id', 'desc' => 'information_description', 'store' => 'information_to_store', 'title' => 'title',
                           'data' => ['bottom', 'sort_order', 'status', 'noindex']],
        'manufacturer' => ['table' => 'manufacturer', 'id' => 'manufacturer_id', 'desc' => 'manufacturer_description', 'store' => 'manufacturer_to_store', 'title' => 'name',
                           'data' => ['name', 'image', 'sort_order', 'noindex']], // noindex: ocStore's "index this page" switch
        'article'      => ['table' => 'article', 'id' => 'article_id', 'desc' => 'article_description', 'store' => 'article_to_store', 'title' => 'name',
                           'data' => ['topic_id', 'author', 'status']],
        'topic'        => ['table' => 'topic', 'id' => 'topic_id', 'desc' => 'topic_description', 'store' => 'topic_to_store', 'title' => 'name',
                           'data' => ['sort_order', 'status']],
    ];

    /**
     * Page types that live in different tables depending on the store: a profile describes them as data - tables,
     * the columns the AI may set, the link to categories, the category tree, the storefront route - and is chosen by
     * the columns the store really has (sign), never by a table name alone. article = a blog post, topic = a blog
     * category. The first profile whose sign fits wins; the owner's own mapping (module_mcp_server_page_types) first.
     */
    const PROFILES = [
        'article' => [
            'oc41' => ['label' => 'OpenCart 4.1 blog', 'table' => 'article', 'id' => 'article_id', 'desc' => 'article_description', 'store' => 'article_to_store', 'title' => 'name',
                       'data' => ['topic_id', 'author', 'status', 'date_added'], 'sign' => ['article' => ['topic_id', 'author'], 'topic' => ['topic_id']],
                       'route' => 'cms/blog{sep}info', 'image_in' => 'desc'],
            'ocstore' => ['label' => 'ocStore blog', 'table' => 'article', 'id' => 'article_id', 'desc' => 'article_description', 'store' => 'article_to_store', 'title' => 'name',
                          'data' => ['image', 'date_available', 'date_added', 'sort_order', 'status', 'noindex', 'article_review'],
                          'sign' => ['article' => ['date_available', 'article_review'], 'article_to_blog_category' => ['article_id', 'blog_category_id'], 'blog_category' => ['blog_category_id']],
                          'route' => 'blog/article', 'image_in' => 'main',
                          'cats' => ['table' => 'article_to_blog_category', 'col' => 'blog_category_id', 'main' => 'main_blog_category', 'ref' => 'blog_category']],
        ],
        'topic' => [
            'oc41' => ['label' => 'OpenCart 4.1 blog topics', 'table' => 'topic', 'id' => 'topic_id', 'desc' => 'topic_description', 'store' => 'topic_to_store', 'title' => 'name',
                       'data' => ['sort_order', 'status'], 'sign' => ['topic' => ['topic_id', 'status'], 'article' => ['topic_id', 'author']],
                       'route' => 'cms/blog', 'image_in' => 'desc'],
            'ocstore' => ['label' => 'ocStore blog categories', 'table' => 'blog_category', 'id' => 'blog_category_id', 'desc' => 'blog_category_description', 'store' => 'blog_category_to_store', 'title' => 'name',
                          'data' => ['image', 'parent_id', 'top', 'column', 'sort_order', 'status', 'noindex'],
                          'sign' => ['blog_category' => ['blog_category_id', 'parent_id'], 'blog_category_path' => ['blog_category_id', 'path_id']],
                          'route' => 'blog/category', 'image_in' => 'main', 'path' => 'blog_category_path'],
        ],
    ];

    /** Why an entity is missing, worded so the AI tells the user instead of saving the text somewhere else. */
    public static function missing(Context $c, string $entity): string {
        if (!isset(self::PROFILES[$entity])) {
            return "This OpenCart version has no $entity pages.";
        }

        $known = [];

        foreach (self::PROFILES as $profiles) {
            foreach ($profiles as $p) {
                $known = array_merge($known, array_keys($p['sign']), [$p['table'], $p['desc'], $p['store']]);
            }
        }

        $blog = array_values(array_diff(array_map(function ($r) use ($c) { return substr((string)reset($r), strlen($c->db->prefix())); },
            $c->db->rows("SHOW TABLES LIKE '" . $c->db->esc(addcslashes($c->db->prefix(), '_%')) . "%blog%'")), $known));
        $labels = [];

        foreach (self::PROFILES[$entity] as $p) {
            $labels[] = $p['label'];
        }

        $msg = "This store has no blog the MCP server knows (" . implode(', ', array_unique($labels)) . "), so $entity pages cannot be saved here.";

        if ($blog) {
            $msg .= ' Its blog belongs to another module (tables: ' . implode(', ', array_slice($blog, 0, 6)) . '): the store owner can connect it under MCP Server → Settings → Page types.';
        }

        return $msg . ' Tell the user; do not save the text as another kind of page unless they ask for it.';
    }

    /**
     * The profile of a page type this store has, or null. A blog the call names wins; then the owner's mapping; then
     * the one blog in use (the store map's signs), the shipped ones and the ones of other modules alike. Two blogs in
     * use: a write asks the user which one (blog_choice), a read takes the one with more signs. No blog in use: the
     * shipped profile with the most signs, in the listed order on a tie; another module's tables alone are not enough.
     */
    public static function profile(Context $c, string $entity): ?array {
        if (!isset(self::PROFILES[$entity])) {
            return null;
        }

        $blogs = array_values(array_filter(self::blogs($c), function ($b) use ($entity) { return isset($b[$entity]); }));

        if ($c->blog !== null && trim($c->blog) !== '') {
            $want = mb_strtolower(trim($c->blog));

            foreach ($blogs as $b) {
                if (in_array($want, array_map('mb_strtolower', $b['names']), true)) {
                    return $b[$entity];
                }
            }

            throw new ToolException('blog: this store has no blog "' . $c->blog . '"' . ($blogs ? '. Its blogs: ' . implode(', ', array_map(function ($b) { return '"' . $b['label'] . '"'; }, $blogs)) . '.' : '.'));
        }

        foreach ($blogs as $b) {
            if ($b['key'] === 'owner') {
                return $b[$entity];
            }
        }

        $live = array_values(array_filter($blogs, function ($b) { return $b['score'] >= StoreMap::LIVE_SCORE; }));

        if (count($live) > 1 && $c->blogStrict) {
            throw new ToolException(self::blogChoice($live, $entity));
        }

        $pick = $live ?: array_values(array_filter($blogs, function ($b) { return $b['key'] !== 'found'; }));
        usort($pick, function ($a, $b) { return $b['score'] <=> $a['score'] ?: $a['order'] <=> $b['order']; });

        return $pick ? $pick[0][$entity] : null;
    }

    /** The question to the user when two blogs are in use: each with what shows it is used, and how to repeat the call. */
    public static function blogChoice(array $live, string $entity): string {
        $list = array_map(function ($b) {
            return '"' . $b['label'] . '" (' . $b['posts'] . ' posts' . ($b['last_post'] !== '' ? ', last ' . $b['last_post'] : '') . ($b['signs'] ? '; ' . implode('; ', array_slice($b['signs'], 0, 3)) : '') . ')';
        }, $live);

        return 'BLOG CHOICE: this store has ' . count($live) . ' blogs in use: ' . implode(' and ', $list) . '. Nothing was saved. Ask the user which blog the '
            . ($entity === 'topic' ? 'blog category' : 'post') . ' goes to, then repeat the same call with blog = ' . implode(' or ', array_map(function ($b) { return '"' . $b['label'] . '"'; }, $live))
            . '. Use the same blog for the rest of this conversation.';
    }

    /**
     * Every blog this store could write to, built once per request: the owner's mapping, the shipped profiles whose
     * columns fit, the blog modules the store map found (tables, links, tree and SEO key read from the database, the
     * storefront route from the module's own routes). Each: key, label, names it answers to, score, posts, signs and
     * its article / topic profiles.
     * @return array[]
     */
    public static function blogs(Context $c): array {
        return $c->memo('blogs', function () use ($c) {
            $map = [];

            try {
                foreach (StoreMap::of($c)['blogs'] ?? [] as $b) {
                    $map[$b['table'] . ':' . $b['profile']] = $b;
                }
            } catch (\Throwable $e) {
                // the map is a hint: without it the shipped profiles still work as before
            }

            $info = function (string $table, string $profile) use ($map) {
                $m = $map[$table . ':' . $profile] ?? [];

                return ['score' => (int)($m['score'] ?? 0), 'posts' => (int)($m['posts'] ?? 0), 'last_post' => (string)($m['last_post'] ?? ''), 'signs' => (array)($m['signs'] ?? [])];
            };
            $out = [];
            $order = 0;
            $owner = PageTypes::owner($c);
            $taken = [];

            if ($owner) {
                $b = ['key' => 'owner', 'label' => (string)($owner['article']['label'] ?? $owner['topic']['label'] ?? 'Blog'), 'order' => $order++];

                foreach (['article', 'topic'] as $e) {
                    if (isset($owner[$e]) && self::fits($c, $owner[$e])) {
                        $b[$e] = $owner[$e] + ['profile' => 'owner'];
                        $taken[$owner[$e]['table']] = true;
                    }
                }

                $t = (string)($owner['article']['table'] ?? $owner['topic']['table'] ?? '');
                $b['names'] = array_values(array_filter([$b['label'], $t, 'owner']));
                $out[] = $b + $info($t, 'owner');
            }

            foreach (self::PROFILES['article'] as $name => $p) {
                $b = ['key' => $name, 'label' => $p['label'], 'names' => [$p['label'], $name, $p['table']], 'order' => $order++];

                foreach (['article', 'topic'] as $e) {
                    $q = self::PROFILES[$e][$name] ?? null;

                    if ($q && !isset($taken[$q['table']]) && self::fits($c, $q)) {
                        $b[$e] = $q + ['profile' => (string)$name];
                    }
                }

                if (isset($b['article']) || isset($b['topic'])) {
                    $out[] = $b + $info($p['table'], (string)$name);
                }
            }

            // other blog modules: written to once the store shows they are in use (profile() decides), never by their tables alone
            try {
                $found = PageTypes::discover($c->db);
            } catch (\Throwable $e) {
                $found = [];
            }

            foreach ($found['article'] ?? [] as $art) {
                if (isset($taken[$art['table']])) {
                    continue;
                }

                $m = $map[$art['table'] . ':found'] ?? [];
                $routes = (array)($m['routes'] ?? []);
                $probe = (int)($m['score'] ?? 0) >= StoreMap::LIVE_SCORE ? $c : null; // the storefront is asked only about a blog in use
                $top = null;

                foreach ($found['topic'] ?? [] as $t) {
                    if (isset($art['cats']['ref']) && $t['table'] === $art['cats']['ref']) {
                        $top = $t;
                    }
                }

                $prof = PageTypes::normalize(array_filter(['article' => $art + ['route' => PageTypes::pickRoute($routes, 'article', $probe, $art)],
                                                           'topic' => $top ? $top + ['route' => PageTypes::pickRoute($routes, 'topic', $probe, $top)] : null]));
                $b = ['key' => 'found', 'label' => $art['table'], 'names' => array_values(array_filter([$art['table'], (string)($m['token'] ?? ''), $top['table'] ?? ''])), 'order' => $order++];

                foreach ($prof as $e => $q) {
                    if (self::fits($c, $q)) {
                        $b[$e] = $q + ['profile' => 'found'];
                    }
                }

                if (isset($b['article'])) {
                    $out[] = $b + $info($art['table'], 'found');
                }
            }

            return $out;
        });
    }

    /** All tables and columns of a profile's sign exist (and its main table). */
    public static function fits(Context $c, array $p): bool {
        if (empty($p['table']) || !$c->hasTable((string)$p['table'])) {
            return false;
        }

        foreach ((array)($p['sign'] ?? []) as $table => $cols) {
            foreach ((array)$cols as $col) {
                if (!$c->hasColumn((string)$table, (string)$col)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Definition of an entity this store has (article / topic exist from OpenCart 4.1), with the columns it really has. */
    public static function def(Context $c, string $entity): ?array {
        // a table of the same name left by another blog module is not this blog: writing there would answer "created"
        // for a page the storefront never shows, so profiled types need their whole sign
        $d = isset(self::PROFILES[$entity]) ? self::profile($c, $entity) : (self::DEF[$entity] ?? null);

        if ($d === null || !$c->hasTable($d['table'])) {
            return null;
        }

        $d['entity'] = $entity;
        $d['data'] = array_values(array_filter($d['data'], function ($col) use ($c, $d) { return $c->hasColumn($d['table'], $col); }));
        $d['desc'] = !empty($d['desc']) && $c->hasTable($d['desc']) ? $d['desc'] : null;
        $d['store'] = !empty($d['store']) && $c->hasTable($d['store']) ? $d['store'] : null;

        if (!empty($d['cats']) && !$c->hasTable((string)$d['cats']['table'])) {
            unset($d['cats']);
        }
        $d['texts'] = $d['desc'] ? array_values(array_diff(self::columns($c->db, $d['desc']), [$d['id'], 'language_id'])) : [];

        return $d;
    }

    public static function columns(Db $db, string $table): array {
        return array_map(function ($r) { return $r['Field']; }, $db->rows("SHOW COLUMNS FROM " . $db->t($table)));
    }

    /**
     * column => the most characters it holds (varchar / char). OpenCart's driver switches MySQL to a non-strict mode, so a
     * longer value is cut off silently - callers refuse it instead.
     * @return array<string,int>
     */
    public static function maxLengths(Db $db, string $table): array {
        static $cache = [];
        $key = $db->t($table);

        if (!isset($cache[$key])) {
            $cache[$key] = [];

            foreach ($db->rows("SHOW COLUMNS FROM " . $key) as $r) {
                if (preg_match('/^(var)?char\((\d+)\)/i', (string)$r['Type'], $m)) {
                    $cache[$key][(string)$r['Field']] = (int)$m[2];
                }
            }
        }

        return $cache[$key];
    }

    /** "texts.uk-ua.name: at most 255 characters (this one has 300)" when $stored does not fit $column, else null. */
    public static function tooLong(Db $db, string $table, string $column, string $stored, string $label): ?string {
        $max = self::maxLengths($db, $table)[$column] ?? null;
        $len = function_exists('mb_strlen') ? mb_strlen($stored, 'UTF-8') : strlen($stored);

        return $max !== null && $len > $max ? "$label: at most $max characters (this one has $len" . ($stored !== html_entity_decode($stored, ENT_QUOTES, 'UTF-8') ? ', counting & and quotes as stored' : '') . '); shorten it. Nothing was changed.' : null;
    }

    /** A language given as its code ("uk-ua") or id: its language_id, or null when the store has no such language. */
    public static function languageId(array $langs, $given): ?int {
        foreach ($langs as $id => $l) {
            if (strtolower($l['code']) === strtolower(trim((string)$given)) || (string)$id === trim((string)$given)) {
                return (int)$id;
            }
        }

        return null;
    }

    /** The one answer for a language the store does not have. */
    public static function unknownLanguage(array $langs, $given): string {
        return "Unknown language '" . $given . "'. Store languages: " . implode(', ', array_column($langs, 'code')) . '.';
    }

    /**
     * Languages the owner keeps for themselves ("Languages the AI works with", unticked): the AI reads their texts but never
     * writes them. language_id => code; never all of them (a store with every language closed writes in all, as before).
     * @return array<int,string>
     */
    public static function lockedLanguages(Context $c): array {
        return $c->memo('lang_off', function () use ($c) {
            $off = $c->content()->settings()['lang_off'] ?? [];

            if (!$off) {
                return [];
            }

            $out = [];
            $langs = self::languages($c->db);

            foreach ($langs as $lid => $l) {
                if (in_array(strtolower($l['code']), $off, true)) {
                    $out[$lid] = $l['code'];
                }
            }

            return count($out) < count($langs) ? $out : [];
        });
    }

    /** The one answer for a write in a locked language; null when the language is open. */
    public static function lockedLanguage(Context $c, int $languageId): ?string {
        $locked = self::lockedLanguages($c);

        if (!isset($locked[$languageId])) {
            return null;
        }

        $open = array_diff_key(array_map(function ($l) { return $l['code']; }, self::languages($c->db)), $locked);

        return "The store owner keeps the texts in " . $locked[$languageId] . " for themselves: the AI reads them but does not change them. Write only in "
            . implode(', ', $open) . " (module Settings > 'Languages the AI works with'). Nothing was changed.";
    }

    /** The language a text without one goes to: the store language, or the first open one when the owner keeps it. */
    public static function writeLanguage(Context $c): int {
        $locked = self::lockedLanguages($c);

        if (!isset($locked[(int)$c->languageId])) {
            return (int)$c->languageId;
        }

        return (int)array_key_first(array_diff_key(self::languages($c->db), $locked));
    }

    /** @return array<int, array{code:string,name:string,status:int}> */
    public static function languages(Db $db): array {
        $out = [];

        foreach ($db->rows("SELECT `language_id`, `code`, `name`, `status` FROM " . $db->t('language') . " ORDER BY `sort_order`, `language_id`") as $r) {
            $out[(int)$r['language_id']] = ['code' => (string)$r['code'], 'name' => (string)$r['name'], 'status' => (int)$r['status']];
        }

        return $out;
    }

    /**
     * New row with every column that has no default filled with an empty value of its type, the way the admin forms
     * write them: stores add columns of their own (ocStore meta_h1, modules), MySQL in strict mode refuses an INSERT that
     * leaves a NOT NULL column out, and OpenCart 4.1 pages break on NULL texts (setTitle(null)).
     */
    public static function fillRequired(Db $db, string $table, array $row): array {
        foreach ($db->rows("SHOW COLUMNS FROM " . $db->t($table)) as $col) {
            $name = $col['Field'];

            if (array_key_exists($name, $row) || $col['Default'] !== null || stripos((string)$col['Extra'], 'auto_increment') !== false) {
                continue;
            }

            $type = strtolower((string)$col['Type']);

            if ($col['Null'] !== 'NO' && !preg_match('/char|text|int|decimal|float|double/', $type)) {
                continue; // nullable dates and blobs stay NULL
            }

            if (preg_match('/int|decimal|float|double|bit/', $type)) {
                $row[$name] = 0;
            } elseif (strpos($type, 'datetime') === 0 || strpos($type, 'timestamp') === 0) {
                $row[$name] = ['NOW()'];
            } elseif (strpos($type, 'date') === 0) {
                $row[$name] = ['CURDATE()'];
            } else {
                $row[$name] = '';
            }
        }

        return $row;
    }

    /** INSERT ... SET from a row; values given as [expr] are written as SQL (NOW()). */
    public static function insert(Db $db, string $table, array $row): int {
        $set = [];

        foreach ($row as $k => $v) {
            $set[] = "`$k` = " . (is_array($v) ? $v[0] : ($v === null ? 'NULL' : $db->q((string)$v)));
        }

        $db->exec("INSERT INTO " . $db->t($table) . " SET " . implode(', ', $set));

        return (int)$db->lastId();
    }

    // ------------------------------------------------------------------ SEO keywords

    /** WHERE for the seo_url rows of one page. */
    public static function seoWhere(Context $c, array $d, int $id): string {
        $db = $c->db;

        if ($c->hasColumn('seo_url', 'key')) {
            if ($d['entity'] === 'category') {
                return "`key` = 'path' AND `value` = " . $db->q(self::categoryPath($db, $id));
            }

            return "`key` = " . $db->q($d['seo'] ?? $d['id']) . " AND `value` = " . $db->q((string)$id);
        }

        return "`query` = " . $db->q(($d['seo'] ?? $d['id']) . '=' . $id);
    }

    /** language_id => keyword of one store (the default store unless given). */
    public static function keywords(Context $c, array $d, int $id, int $storeId = 0): array {
        $out = [];

        foreach ($c->db->rows("SELECT `language_id`, `keyword` FROM " . $c->db->t('seo_url') . " WHERE `store_id` = " . $storeId . " AND " . self::seoWhere($c, $d, $id)) as $r) {
            $out[(int)$r['language_id']] = (string)$r['keyword'];
        }

        return $out;
    }

    /** Keyword free in every given store, or the first free one with -2, -3 ... ($own = the page's own rows, which do not count). */
    public static function freeKeyword(Context $c, string $keyword, string $ownWhere, array $storeIds = [0]): string {
        $db = $c->db;
        $try = $keyword;
        $stores = $db->ints($storeIds ?: [0]);

        for ($n = 2; $n < 200; $n++) {
            $taken = $db->value("SELECT COUNT(*) AS n FROM " . $db->t('seo_url') . " WHERE `store_id` IN (" . $stores . ") AND (`keyword` = " . $db->q($try)
                . ($c->hasColumn('seo_url', 'key') ? " OR `keyword` LIKE " . $db->q('%/' . $try) : '') . ") AND NOT (" . $ownWhere . ")", 'n');

            if ((int)$taken === 0) {
                return $try;
            }

            $suffix = '-' . $n;
            $try = substr($keyword, 0, Slug::MAX - strlen($suffix)) . $suffix;
        }

        return $keyword . '-' . substr(md5(uniqid('', true)), 0, 6);
    }

    /** Write the keyword of one language (insert or update). OpenCart 4 categories keep the whole path: parent/child. */
    public static function setKeyword(Context $c, array $d, int $id, int $languageId, string $keyword, int $storeId = 0): void {
        $db = $c->db;
        $where = self::seoWhere($c, $d, $id);
        $existing = $db->row("SELECT * FROM " . $db->t('seo_url') . " WHERE `store_id` = " . $storeId . " AND `language_id` = " . $languageId . " AND " . $where . " LIMIT 1");

        if ($existing !== null) {
            $db->exec("UPDATE " . $db->t('seo_url') . " SET `keyword` = " . $db->q($keyword) . " WHERE `seo_url_id` = " . (int)$existing['seo_url_id']);

            return;
        }

        if ($c->hasColumn('seo_url', 'key')) {
            $key = $d['entity'] === 'category' ? 'path' : ($d['seo'] ?? $d['id']);
            $value = $d['entity'] === 'category' ? self::categoryPath($db, $id) : (string)$id;
            $row = ['store_id' => $storeId, 'language_id' => $languageId, 'key' => $key, 'value' => $value, 'keyword' => $keyword];

            if ($c->hasColumn('seo_url', 'sort_order')) {
                $row['sort_order'] = (int)$db->value("SELECT MAX(`sort_order`) AS s FROM " . $db->t('seo_url') . " WHERE `key` = " . $db->q($key), 's');
            }
        } else {
            $row = ['store_id' => $storeId, 'language_id' => $languageId, 'query' => ($d['seo'] ?? $d['id']) . '=' . $id, 'keyword' => $keyword];
        }

        self::insert($db, 'seo_url', $row);
    }

    /** Keyword of the parent category on OpenCart 4 (its full path), '' for a top category. */
    public static function parentKeyword(Context $c, int $parentId, int $languageId, int $storeId = 0): string {
        if ($parentId <= 0 || !$c->hasColumn('seo_url', 'key')) {
            return '';
        }

        return (string)$c->db->value("SELECT `keyword` FROM " . $c->db->t('seo_url') . " WHERE `store_id` = " . $storeId . " AND `language_id` = " . $languageId
            . " AND `key` = 'path' AND `value` = " . $c->db->q(self::categoryPath($c->db, $parentId)) . " LIMIT 1", 'keyword');
    }

    // ------------------------------------------------------------------ categories

    public static function categoryPath(Db $db, int $categoryId): string {
        $ids = array_map(function ($r) { return (int)$r['path_id']; }, $db->rows("SELECT `path_id` FROM " . $db->t('category_path') . " WHERE `category_id` = " . $categoryId . " ORDER BY `level`"));

        return $ids ? implode('_', $ids) : (string)$categoryId;
    }

    /** category_path rows of a new category: the parent's path plus itself (what the admin model does). */
    public static function addCategoryPath(Db $db, int $categoryId, int $parentId, string $table = 'category_path', string $idCol = 'category_id'): void {
        $level = 0;

        foreach ($db->rows("SELECT `path_id` FROM " . $db->t($table) . " WHERE `" . $idCol . "` = " . $parentId . " ORDER BY `level`") as $r) {
            $db->exec("INSERT INTO " . $db->t($table) . " SET `" . $idCol . "` = " . $categoryId . ", `path_id` = " . (int)$r['path_id'] . ", `level` = " . $level++);
        }

        $db->exec("INSERT INTO " . $db->t($table) . " SET `" . $idCol . "` = " . $categoryId . ", `path_id` = " . $categoryId . ", `level` = " . $level);
    }

    // ------------------------------------------------------------------ storefront links

    /** Storefront URL of a page in one language. */
    public static function url(Context $c, array $d, int $id, int $languageId): string {
        $sep = $c->ocMajor() >= 4 ? (version_compare($c->ocVersion ?: '4.0.2.0', '4.0.2.0', '>=') ? '.' : '|') : '/';
        $q = ['product' => ['route' => 'product/product', 'product_id' => $id],
              'category' => ['route' => 'product/category', 'path' => self::categoryPath($c->db, $id)],
              'information' => ['route' => 'information/information', 'information_id' => $id],
              'manufacturer' => ['route' => 'product/manufacturer' . $sep . 'info', 'manufacturer_id' => $id]][$d['entity']]
            ?? ['route' => str_replace('{sep}', $sep, (string)($d['route'] ?? '')), ($d['seo'] ?? $d['id']) => $id]; // profiled page types
        $links = new SeoLinks($c->db, $c->ocMajor(), $c->storeUrl, $c->storeId, (bool)$c->config('config_seo_url', false));
        $rel = $links->resolve($q, $languageId);
        $p = parse_url($c->storeUrl);
        $origin = isset($p['host']) ? ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') : '';

        return $origin . $rel;
    }
}
