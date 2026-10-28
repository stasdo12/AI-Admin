<?php
namespace OcMcp;

/**
 * Server-side content rules: every text the AI writes into the store passes through here, whatever tool wrote it.
 * Descriptions are HTML cleaned to the owner's level (scripts and event handlers never survive), tables become
 * responsive, images centered and never stretched with alt text, links to the own store relative and in the language
 * of the text. Names and meta fields are plain text. Values go in and out decoded; OpenCart stores them escaped
 * (see store()/unstore()).
 */
final class ContentPolicy {
    /**
     * The owner's switches (Settings tab). Behaviour first: what the AI must ask before; then styling and images.
     * Fixed values the owner does not need to see: images at most IMG_WIDTH px wide, IMG_HOURLY imports per hour.
     */
    const DEFAULTS = [
        'ask_first'    => true,  // every change: preview ("before -> after") and the user's yes first
        'bulk_preview' => true,  // bulk changes of more than one product: preview first, also when ask_first is off
        'drafts'       => true,  // new pages are created disabled
        'url_confirm'  => true,  // an existing SEO URL changes only with the user's explicit yes
        'html'         => true,  // HTML tags in descriptions (off = plain text)
        'css'          => false, // inline styles on HTML (needs html)
        'emoji'        => false, // emoji anywhere
        'video'        => true,  // YouTube / Vimeo embeds, responsive
        'nofollow'     => false, // rel="nofollow" on every external link
        'img_resize'   => true,  // shrink images wider than IMG_WIDTH
        'img_delete'   => false, // the AI may delete photos from the site (delete_image: to the trash, rolls back)
        // language codes the AI reads but never writes (names, texts, meta, SEO URLs); the owner unticks them, a language added
        // to the store later is open
        'lang_off'     => [],
    ];
    const IMG_WIDTH = 2000;
    const IMG_HOURLY = 200;
    const IMG_MAX_BYTES = 2097152; // a stored image is at most 2 MB

    /** Columns of the description tables: html = rich text, text = plain (names, meta, tags). */
    const FIELDS = [
        'product_description'      => ['html' => ['description'], 'text' => ['name', 'meta_title', 'meta_description', 'meta_keyword', 'meta_h1', 'tag']],
        'category_description'     => ['html' => ['description'], 'text' => ['name', 'meta_title', 'meta_description', 'meta_keyword', 'meta_h1']],
        'information_description'  => ['html' => ['description'], 'text' => ['title', 'meta_title', 'meta_description', 'meta_keyword', 'meta_h1']],
        'manufacturer_description' => ['html' => ['description'], 'text' => ['name', 'meta_title', 'meta_description', 'meta_keyword', 'meta_h1']],
        'article_description'      => ['html' => ['description'], 'text' => ['name', 'meta_title', 'meta_description', 'meta_keyword', 'meta_h1', 'tag', 'image_title']],
        'topic_description'        => ['html' => ['description'], 'text' => ['name', 'meta_title', 'meta_description', 'meta_keyword', 'meta_h1']],
    ];

    const TAGS_BASIC = ['p', 'br', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'a', 'img', 'table', 'thead', 'tbody',
                        'tfoot', 'tr', 'th', 'td', 'caption', 'blockquote', 'div', 'span', 'hr', 'sup', 'sub', 'figure', 'figcaption'];
    const TAGS_EXTENDED = ['h5', 'h6', 'dl', 'dt', 'dd', 'pre', 'code', 'small', 'mark', 's', 'del', 'ins', 'abbr', 'cite', 'q', 'section',
                           'article', 'details', 'summary', 'colgroup', 'col', 'address', 'time'];
    /** Removed together with everything inside them. */
    const TAGS_DROP = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'option', 'textarea',
                       'noscript', 'template', 'svg', 'math', 'link', 'meta', 'base', 'frame', 'frameset', 'head', 'title', 'audio', 'video',
                       'source', 'track', 'canvas', 'dialog', 'portal'];
    const ATTRS = ['a' => ['href', 'title', 'target', 'rel'], 'img' => ['src', 'alt', 'title', 'loading'], 'td' => ['colspan', 'rowspan'],
                   'th' => ['colspan', 'rowspan', 'scope'], 'ol' => ['start', 'type'], 'li' => ['value'], 'col' => ['span'],
                   'colgroup' => ['span'], 'abbr' => ['title'], 'time' => ['datetime'], 'q' => ['cite'], 'blockquote' => ['cite']];
    const CSS_PROPS = ['text-align', 'font-weight', 'font-style', 'text-decoration', 'color', 'background-color', 'vertical-align',
                       'list-style-type', 'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right', 'padding',
                       'padding-top', 'padding-bottom', 'padding-left', 'padding-right'];
    const VIDEO_SRC = '~^https://(?:www\.)?(?:youtube\.com/embed/|youtube-nocookie\.com/embed/|player\.vimeo\.com/video/)[A-Za-z0-9_-]+(?:\?[A-Za-z0-9_=&;.-]*)?$~';
    const IMG_STYLE = 'display:block;max-width:100%;height:auto;margin:0 auto';
    const EMOJI = '/(?:[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{1F1E6}-\x{1F1FF}\x{2300}-\x{23FF}](?:\x{FE0F}|[\x{1F3FB}-\x{1F3FF}])*(?:\x{200D}[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}](?:\x{FE0F})?)*)|\x{FE0F}|\x{20E3}/u';

    /** @var array */
    private $s;
    /** @var string[] hosts of the own store, without www. */
    private $hosts;
    /** @var callable|null (array $query, int $languageId) => string relative url */
    private $links;
    /** @var array per-call state */
    private $st = [];

    /** @var callable|null (string $table, string $column) => bool: the column stores 4-byte UTF-8 (utf8mb4) */
    private $mb4;

    public function __construct(array $settings = [], array $hosts = [], ?callable $links = null, ?callable $mb4 = null) {
        $this->s = self::normalize($settings);
        $this->hosts = array_values(array_filter(array_map([self::class, 'host'], $hosts)));
        $this->links = $links;
        $this->mb4 = $mb4;
    }

    /** Owner settings from the module config (JSON string or array), unknown keys dropped, values coerced. */
    public static function normalize($settings): array {
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        $in = is_array($settings) ? $settings : [];

        // settings saved by the first version of the module
        if (isset($in['html']) && is_string($in['html'])) {
            $in['html'] = $in['html'] !== 'text';
        }

        if (!isset($in['emoji']) && (!empty($in['emoji_meta']))) {
            $in['emoji'] = true;
        }

        $s = self::DEFAULTS;

        foreach ($in as $k => $v) {
            if (!array_key_exists($k, $s)) {
                continue;
            }

            $s[$k] = $k === 'lang_off' ? self::langCodes($v) : (bool)$v;
        }

        if (!$s['html']) {
            $s['css'] = false; // styles exist only on HTML
        }

        return $s;
    }

    /** "uk-ua", " EN-gb " ... -> unique lower-case codes; anything else is dropped. */
    private static function langCodes($v): array {
        $out = [];

        foreach (is_array($v) ? $v : [] as $code) {
            $code = strtolower(trim(is_scalar($code) ? (string)$code : ''));

            if ($code !== '' && preg_match('/^[a-z0-9_-]{2,32}$/', $code)) {
                $out[$code] = $code;
            }
        }

        return array_values($out);
    }

    public function settings(): array {
        return $this->s;
    }

    /** 'html', 'text' or null for a column the rules do not cover. */
    public static function kind(string $table, string $column): ?string {
        $f = self::FIELDS[$table] ?? null;

        if ($f === null) {
            return null;
        }

        return in_array($column, $f['html'], true) ? 'html' : (in_array($column, $f['text'], true) ? 'text' : null);
    }

    /** OpenCart keeps request data escaped (Request::clean): what goes into the database. */
    public static function store(string $v): string {
        return htmlspecialchars($v, ENT_COMPAT, 'UTF-8');
    }

    /**
     * A plain text the way OpenCart's own forms store it (Request::clean escapes every input). Admin templates print stored
     * texts unescaped, inside tags and inside value="..." - a raw quote or an unquoted onerror= would run in the admin (audit
     * 2026-10-12). Tags are dropped; an already stored (escaped) text stays the same. JSON (custom_field, OC4 payment_method)
     * keeps its structure, its texts treated the same way.
     */
    public static function plain(string $s): string {
        $first = ltrim($s)[0] ?? '';

        if (($first === '{' || $first === '[') && is_array($json = json_decode($s, true))) {
            array_walk_recursive($json, function (&$leaf) { if (is_string($leaf)) { $leaf = self::plain($leaf); } });

            return (string)json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return self::store(strip_tags(html_entity_decode($s, ENT_QUOTES, 'UTF-8')));
    }

    public static function unstore(string $v): string {
        return html_entity_decode($v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Clean one column value (decoded) of a description table.
     * @param array $ctx title => page name (for alt), language_id => language of the text
     * @return array{0:string,1:string[]} cleaned value, notes for the AI
     */
    public function field(string $table, string $column, string $value, array $ctx = []): array {
        $kind = self::kind($table, $column);

        if ($kind === 'html') {
            [$v, $notes] = $this->html($value, $ctx);
        } elseif ($kind === 'text') {
            [$v, $notes] = $this->text($value, $column);
        } else {
            [$v, $notes] = [$value, []];
        }

        // OpenCart 3 creates its tables as utf8 (3 bytes): emoji would cut the text off where they start
        if ($this->mb4 !== null && preg_match('/[\x{10000}-\x{10FFFF}]/u', $v) && !call_user_func($this->mb4, $table, $column)) {
            if ($kind === 'html') {
                $v = preg_replace_callback('/[\x{10000}-\x{10FFFF}]/u', function ($m) { return '&#x' . strtoupper(dechex(self::codepoint($m[0]))) . ';'; }, $v);
            } else {
                $v = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $v)));
                $notes[] = "$column: emoji and other 4-byte characters removed (this database table cannot store them)";
            }
        }

        return [$v, $notes];
    }

    private static function codepoint(string $ch): int {
        if (function_exists('mb_ord')) {
            return (int)mb_ord($ch, 'UTF-8');
        }

        $u = unpack('N', mb_convert_encoding($ch, 'UCS-4BE', 'UTF-8'));

        return (int)$u[1];
    }

    /** @return array{0:string,1:string[]} */
    public function text(string $value, string $column = 'name'): array {
        $notes = [];
        $v = self::decodeOnce($value);
        $plain = strip_tags($v);

        if ($plain !== $v) {
            $notes[] = "$column: HTML removed (plain text field)";
        }

        $plain = html_entity_decode($plain, ENT_QUOTES, 'UTF-8');

        if (!$this->s['emoji']) {
            $n = preg_match_all(self::EMOJI, $plain);

            if ($n) {
                $plain = preg_replace(self::EMOJI, '', $plain);
                $notes[] = "$column: emoji removed (not allowed in names and meta fields)";
            }
        }

        $plain = trim(preg_replace('/\s+/u', ' ', $plain));

        return [$plain, $notes];
    }

    /** @return array{0:string,1:string[]} */
    public function html(string $value, array $ctx = []): array {
        $v = self::decodeOnce($value);

        if (trim($v) === '') {
            return ['', []];
        }

        if (!$this->s['html'] || !class_exists('DOMDocument')) {
            [$t, $notes] = $this->plainParagraphs($v);

            return [$t, $notes];
        }

        $this->st = ['notes' => [], 'emoji' => 0, 'img' => 0, 'ctx' => $ctx, 'count' => []];
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="mcp-root">' . $v . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('mcp-root');

        if ($root === null) {
            return $this->plainParagraphs($v);
        }

        $this->walk($root);
        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        $notes = $this->st['notes'];

        foreach ($this->st['count'] as $what => $n) {
            $notes[] = $n . ' ' . $what;
        }

        return [trim($out), array_values(array_unique($notes))];
    }

    /**
     * Quality of a page's texts for the AI to fix on its own (decoded values of one language).
     * @return string[]
     */
    public static function report(array $f, string $label = ''): array {
        $w = [];
        $p = $label !== '' ? $label . ': ' : '';
        $len = function ($s) { return function_exists('mb_strlen') ? mb_strlen((string)$s, 'UTF-8') : strlen((string)$s); };

        if (array_key_exists('meta_title', $f)) {
            $n = $len($f['meta_title']);

            if ($n === 0) {
                $w[] = $p . 'meta_title is empty (recommended 30-60 characters)';
            } elseif ($n > 60) {
                $w[] = $p . "meta_title is $n characters, search engines cut it after ~60";
            } elseif ($n < 30) {
                $w[] = $p . "meta_title is only $n characters (recommended 30-60)";
            }
        }

        if (array_key_exists('meta_description', $f)) {
            $n = $len($f['meta_description']);

            if ($n === 0) {
                $w[] = $p . 'meta_description is empty (recommended 120-160 characters)';
            } elseif ($n > 160) {
                $w[] = $p . "meta_description is $n characters, search engines cut it after ~160";
            } elseif ($n < 70) {
                $w[] = $p . "meta_description is only $n characters (recommended 120-160)";
            }
        }

        if (array_key_exists('description', $f)) {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$f['description']), ENT_QUOTES, 'UTF-8')));
            $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text));

            if ($words === 0) {
                $w[] = $p . 'description is empty';
            } elseif ($words < 50) {
                $w[] = $p . "description has only $words words (thin content; aim for 150+)";
            }
        }

        if (isset($f['name'], $f['meta_title']) && $f['meta_title'] !== '' && $f['name'] === $f['meta_title']) {
            $w[] = $p . 'meta_title repeats the name word for word; add what the page offers (brand, type, a benefit)';
        }

        return $w;
    }

    // ------------------------------------------------------------------ internals

    /** Text the AI sent already escaped (&lt;p&gt;...) is decoded once, real markup is left as it is. */
    private static function decodeOnce(string $v): string {
        if (strpos($v, '<') === false && strpos($v, '&lt;') !== false) {
            return html_entity_decode($v, ENT_QUOTES, 'UTF-8');
        }

        return $v;
    }

    private function plainParagraphs(string $v): array {
        $t = preg_replace('~<\s*(?:script|style)\b.*?</\s*(?:script|style)\s*>~is', '', $v);
        $t = preg_replace('~<\s*(?:br|/p|/div|/li|/h[1-6])\s*/?>~i', "\n", $t);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
        $out = [];

        foreach (preg_split('/\n\s*\n|\n/u', $t) as $line) {
            $line = trim(preg_replace('/[ \t]+/u', ' ', $line));

            if ($line !== '') {
                $out[] = '<p>' . htmlspecialchars($line, ENT_NOQUOTES, 'UTF-8') . '</p>';
            }
        }

        return [implode("\n", $out), $t !== $v ? ['description: formatting removed (owner allows plain text only)'] : []];
    }

    private function count(string $what): void {
        $this->st['count'][$what] = ($this->st['count'][$what] ?? 0) + 1;
    }

    private function walk(\DOMNode $node): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction || $child instanceof \DOMCdataSection) {
                $node->removeChild($child);
                continue;
            }

            if ($child instanceof \DOMText) {
                $this->emoji($child);
                continue;
            }

            if (!$child instanceof \DOMElement) {
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->nodeName);

            if ($tag === 'iframe' && $this->s['video'] && preg_match(self::VIDEO_SRC, trim($child->getAttribute('src')))) {
                $this->video($child);
                continue;
            }

            if (in_array($tag, self::TAGS_DROP, true)) {
                $node->removeChild($child);
                $this->st['notes'][] = "<$tag> removed (not allowed in store texts)";
                continue;
            }

            if ($tag === 'h1') {
                $child = $this->rename($child, 'h2');
                $tag = 'h2';
                $this->st['notes'][] = 'h1 changed to h2 (the theme already shows the page name as h1)';
            }

            $this->walk($child);

            if (!$this->allowed($tag)) {
                $this->unwrap($child);
                $this->st['notes'][] = "<$tag> tag removed, its text kept";
                continue;
            }

            $this->attributes($child, $tag);

            if ($tag === 'a') {
                $this->link($child);
            } elseif ($tag === 'img') {
                $this->image($child);
            } elseif ($tag === 'table') {
                $this->table($child);
            } elseif ($tag === 'p' && trim(str_replace("\xC2\xA0", ' ', $child->textContent)) === '' && !$child->getElementsByTagName('img')->length
                      && !$child->getElementsByTagName('iframe')->length) {
                $node->removeChild($child); // empty paragraph
            }
        }
    }

    private function allowed(string $tag): bool {
        return in_array($tag, self::TAGS_BASIC, true) || in_array($tag, self::TAGS_EXTENDED, true);
    }

    /** Wrappers this class adds itself: kept as they are, so cleaning a cleaned text changes nothing. */
    const WRAPPERS = ['table-responsive' => 'overflow-x:auto', 'mcp-video' => 'position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%'];

    private function attributes(\DOMElement $el, string $tag): void {
        $keep = self::ATTRS[$tag] ?? [];
        $wrapper = $tag === 'div' ? (self::WRAPPERS[trim($el->getAttribute('class'))] ?? null) : null;

        if ($wrapper !== null) {
            $class = trim($el->getAttribute('class'));

            foreach (iterator_to_array($el->attributes) as $attr) {
                $el->removeAttribute($attr->nodeName);
            }

            $el->setAttribute('class', $class);
            $el->setAttribute('style', $wrapper);

            return;
        }

        if ($this->s['css']) {
            $keep[] = 'class'; // theme classes go with styles
        }

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);

            if ($name === 'style' && $this->s['css']) {
                $css = self::css($attr->nodeValue);

                if ($css === '') {
                    $el->removeAttribute($attr->nodeName);
                } else {
                    $el->setAttribute('style', $css);
                }

                continue;
            }

            if (!in_array($name, $keep, true)) {
                $el->removeAttribute($attr->nodeName);

                if (strpos($name, 'on') === 0) {
                    $this->st['notes'][] = "$name event handler removed";
                }
            }
        }

        foreach (['href', 'src', 'cite'] as $u) {
            if ($el->hasAttribute($u) && !self::safeUrl($el->getAttribute($u), $u === 'href')) {
                $el->removeAttribute($u);
                $this->st['notes'][] = "unsafe $u removed from <$tag>";
            }
        }
    }

    private static function css(string $style): string {
        $out = [];

        foreach (explode(';', $style) as $decl) {
            $p = strpos($decl, ':');

            if ($p === false) {
                continue;
            }

            $prop = strtolower(trim(substr($decl, 0, $p)));
            $val = trim(substr($decl, $p + 1));

            if (in_array($prop, self::CSS_PROPS, true) && $val !== '' && !preg_match('/url\s*\(|expression|javascript|behavior|[<>\\\\@{}]/i', $val)) {
                $out[] = $prop . ':' . $val;
            }
        }

        return implode(';', $out);
    }

    private static function safeUrl(string $url, bool $href): bool {
        $u = strtolower(preg_replace('/[\x00-\x20\x7F]+/', '', html_entity_decode($url, ENT_QUOTES, 'UTF-8')));

        if (!preg_match('/^([a-z][a-z0-9+.-]*):/', $u, $m)) {
            return true; // relative, #anchor, //host
        }

        return in_array($m[1], $href ? ['http', 'https', 'mailto', 'tel'] : ['http', 'https'], true);
    }

    private static function host(string $url): string {
        $h = strtolower((string)(parse_url(strpos($url, '//') === false ? 'http://' . $url : $url, PHP_URL_HOST) ?: ''));

        return strpos($h, 'www.') === 0 ? substr($h, 4) : $h;
    }

    /** Own-store url (absolute on our host, or relative) => [path, query array, fragment]; null for other sites. */
    private function own(string $url): ?array {
        $url = trim($url);

        if ($url === '' || $url[0] === '#' || preg_match('/^(mailto|tel):/i', $url)) {
            return null;
        }

        $p = parse_url($url);

        if ($p === false) {
            return null;
        }

        if (isset($p['host'])) {
            if (!in_array(self::host($p['host']), $this->hosts, true)) {
                return null;
            }
        } elseif (isset($p['scheme'])) {
            return null;
        }

        parse_str($p['query'] ?? '', $q);

        return [$p['path'] ?? '/', $q, $p['fragment'] ?? '', isset($p['host'])];
    }

    private function link(\DOMElement $a): void {
        $href = $a->getAttribute('href');
        $own = $href === '' ? null : $this->own($href);

        if ($own !== null) {
            [$path, $q, $frag, $absolute] = $own;
            $new = null;

            if (isset($q['route']) && $this->links !== null) {
                $new = call_user_func($this->links, $q, (int)($this->st['ctx']['language_id'] ?? 0));
            } elseif ($absolute) {
                $new = ($path === '' ? '/' : $path) . ($q ? '?' . http_build_query($q) : '');
            }

            if ($new !== null) {
                $new .= $frag !== '' ? '#' . $frag : '';

                if ($new !== $href) {
                    $a->setAttribute('href', $new);
                    $this->count('link(s) to the store made relative and in the language of the text');
                }
            }

            $a->removeAttribute('rel');

            if ($a->getAttribute('target') !== '_blank') {
                $a->removeAttribute('target');
            }

            return;
        }

        if ($a->hasAttribute('target') && $a->getAttribute('target') !== '_blank') {
            $a->removeAttribute('target');
        }

        if ($href !== '' && preg_match('~^(https?:)?//~i', $href)) {
            $rel = ['noopener'];

            if ($this->s['nofollow']) {
                $rel[] = 'nofollow';
            }

            foreach (preg_split('/\s+/', strtolower($a->getAttribute('rel'))) as $r) {
                if (in_array($r, ['nofollow', 'sponsored', 'ugc', 'noreferrer'], true) && !in_array($r, $rel, true)) {
                    $rel[] = $r;
                }
            }

            $a->setAttribute('rel', implode(' ', $rel));
        }
    }

    private function image(\DOMElement $img): void {
        $src = $img->getAttribute('src');

        if ($src === '') {
            $img->parentNode->removeChild($img);
            $this->st['notes'][] = 'image without src removed';

            return;
        }

        $own = $this->own($src);

        if ($own !== null && $own[3]) {
            $img->setAttribute('src', ($own[0] ?: '/') . ($own[1] ? '?' . http_build_query($own[1]) : ''));
        } elseif ($own === null && preg_match('~^(https?:)?//~i', $src)) {
            $this->st['notes'][] = 'image from another site: ' . $src . ' (hotlinked; it disappears if that site removes it)';
        }

        $img->setAttribute('style', self::IMG_STYLE);
        $img->setAttribute('loading', 'lazy');
        $this->st['img']++;

        // every image has alt (search engines, screen readers) and title (tooltip): the AI's own words win
        if (trim($img->getAttribute('alt')) === '') {
            $title = trim((string)($this->st['ctx']['title'] ?? ''));

            if ($title !== '') {
                $img->setAttribute('alt', $this->st['img'] > 1 ? $title . ' ' . $this->st['img'] : $title);
                $this->count('image(s) got alt text from the page name');
            } else {
                $this->st['notes'][] = 'image without alt text: add a short description of what it shows';
            }
        }

        if (trim($img->getAttribute('title')) === '' && trim($img->getAttribute('alt')) !== '') {
            $img->setAttribute('title', $img->getAttribute('alt'));
        }

        $ordered = [];

        foreach (['src', 'alt', 'title', 'style', 'loading'] as $name) {
            if ($img->hasAttribute($name)) {
                $ordered[$name] = $img->getAttribute($name);
                $img->removeAttribute($name);
            }
        }

        foreach ($ordered as $name => $value) {
            $img->setAttribute($name, $value); // fixed order: cleaning a cleaned text gives the same bytes
        }
    }

    private function table(\DOMElement $t): void {
        $classes = $this->s['css'] ? preg_split('/\s+/', trim($t->getAttribute('class'))) : [];
        $t->setAttribute('class', implode(' ', array_unique(array_filter(array_merge(['table', 'table-bordered'], $classes)))));
        $parent = $t->parentNode;

        if ($parent instanceof \DOMElement && strtolower($parent->nodeName) === 'div' && preg_match('/\btable-responsive\b/', $parent->getAttribute('class'))) {
            return;
        }

        $wrap = $t->ownerDocument->createElement('div');
        $wrap->setAttribute('class', 'table-responsive');
        $wrap->setAttribute('style', self::WRAPPERS['table-responsive']);
        $parent->replaceChild($wrap, $t);
        $wrap->appendChild($t);
        $this->count('table(s) made responsive');
    }

    private function video(\DOMElement $f): void {
        $doc = $f->ownerDocument;
        $src = trim($f->getAttribute('src'));
        $title = $f->getAttribute('title') ?: 'Video';

        foreach (iterator_to_array($f->attributes) as $a) {
            $f->removeAttribute($a->nodeName);
        }

        while ($f->firstChild) {
            $f->removeChild($f->firstChild);
        }

        $f->setAttribute('src', $src);
        $f->setAttribute('title', $title);
        $f->setAttribute('loading', 'lazy');
        $f->setAttribute('allowfullscreen', 'allowfullscreen');
        $f->setAttribute('style', 'position:absolute;top:0;left:0;width:100%;height:100%;border:0');
        $parent = $f->parentNode;

        if ($parent instanceof \DOMElement && trim($parent->getAttribute('class')) === 'mcp-video') {
            return;
        }

        $wrap = $doc->createElement('div');
        $wrap->setAttribute('class', 'mcp-video');
        $wrap->setAttribute('style', self::WRAPPERS['mcp-video']);
        $parent->replaceChild($wrap, $f);
        $wrap->appendChild($f);
    }

    private function emoji(\DOMText $t): void {
        if ($this->s['emoji']) {
            return; // the owner allows emoji: no limit
        }

        $text = preg_replace(self::EMOJI, '', $t->data);

        if ($text !== $t->data) {
            $t->data = $text;
            $this->st['notes'][] = 'emoji removed (the owner does not allow them)';
        }
    }

    private function rename(\DOMElement $el, string $tag): \DOMElement {
        $new = $el->ownerDocument->createElement($tag);

        foreach (iterator_to_array($el->attributes) as $a) {
            $new->setAttribute($a->nodeName, $a->nodeValue);
        }

        while ($el->firstChild) {
            $new->appendChild($el->firstChild);
        }

        $el->parentNode->replaceChild($new, $el);

        return $new;
    }

    private function unwrap(\DOMElement $el): void {
        $parent = $el->parentNode;

        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }

        $parent->removeChild($el);
    }
}
