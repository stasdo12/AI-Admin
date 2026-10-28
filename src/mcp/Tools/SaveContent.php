<?php
namespace OcMcp\Tools;

/**
 * FREE / demo build: the write tool `save_content` is removed (read-only distribution).
 * Only the small static helpers that the READ path still uses are kept here - the full
 * write orchestration lives in the paid build and ships nowhere in the free package.
 *   - categoryLink()  used by GetContent / FieldPlan to describe a page's category link
 *   - storefront()    used by PageTypes to check a saved page opens (read-only HTTP GET)
 *   - MAX_ITEMS       referenced by Server instructions text
 * This is a plain helper class on purpose: it is NOT registered as a tool.
 */
final class SaveContent {
    const MAX_ITEMS = 100;

    public static function categoryLink(array $d): ?array {
        if ($d['entity'] === 'product') {
            return ['table' => 'product_to_category', 'col' => 'category_id', 'ref' => 'category'];
        }

        return !empty($d['cats']) ? $d['cats'] : null;
    }

    public static function storefront(string $url, string $name, array $d): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6,
                                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; OpenCart-MCP page check)']); // a broken certificate answers 0: "not checked", never a false alarm

        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $what = !empty($d['profile']) ? ($d['label'] ?? 'blog') : $d['entity'];

        if ($code === 0) {
            return ['url' => $url, 'checked' => false, 'note' => 'the storefront did not answer the server itself, so the page was not checked'];
        }

        if ($code === 404) {
            return ['url' => $url, 'http' => 404, 'ok' => false, 'problem' => 'the page is saved but the storefront answers 404: the ' . $what . ' may be switched off in the store settings or not shown by the theme. Tell the user.'];
        }

        if ($code >= 300 && $code < 400) {
            return ['url' => $url, 'http' => $code, 'ok' => true, 'note' => 'the storefront redirects this address (SEO URL or language)'];
        }

        if ($code !== 200) {
            return ['url' => $url, 'http' => $code, 'ok' => false, 'problem' => 'the storefront answers HTTP ' . $code . ' for the page'];
        }

        $text = html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
        $shown = $name === '' || mb_stripos($text, $name) !== false;

        return ['url' => $url, 'http' => 200, 'ok' => $shown] + ($shown ? [] : ['problem' => 'the page opens but does not show its name (a cache of the theme, or another page answers this address)']);
    }
}
