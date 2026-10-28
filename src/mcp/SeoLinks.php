<?php
namespace OcMcp;

/**
 * Links inside store texts, rewritten the way the storefront itself builds them, in the language of the text:
 *   OpenCart 3: index.php?route=product/product&product_id=40  ->  /iphone            (seo_url.query = 'product_id=40')
 *               index.php?route=product/category&path=20_27     ->  /desktops/mac     (one keyword per category)
 *   OpenCart 4: same link                                       ->  /uk-ua/product/iphone (seo_url key/value, sort_order)
 * Without SEO URLs, or when a keyword is missing, the link stays relative: /index.php?route=...(&language=xx on OC4).
 */
final class SeoLinks {
    /** @var Db */
    private $db;
    /** @var int */
    private $major;
    /** @var string base path of the store, '/' or '/shop/' */
    private $base;
    /** @var int */
    private $storeId;
    /** @var bool */
    private $seo;
    /** @var array|null language_id => code */
    private $codes;
    /** @var bool|null */
    private $sortOrder;

    public function __construct(Db $db, int $ocMajor, string $storeUrl, int $storeId, bool $seoUrls) {
        $this->db = $db;
        $this->major = $ocMajor;
        $path = (string)(parse_url($storeUrl, PHP_URL_PATH) ?: '/');
        $this->base = rtrim($path, '/') . '/';
        $this->storeId = $storeId;
        $this->seo = $seoUrls;
    }

    public function resolve(array $query, int $languageId): string {
        unset($query['user_token'], $query['token']);

        if ($this->major >= 4) {
            $code = $this->code($languageId);

            if ($code !== '') {
                $query['language'] = $code; // the link opens the page in the language of the text it stands in
            }
        }

        if ($this->seo) {
            $url = $this->major >= 4 ? $this->oc4($query, $languageId) : $this->oc3($query, $languageId);

            if ($url !== null) {
                return $url;
            }
        }

        return $this->base . 'index.php?' . http_build_query($query);
    }

    private function oc3(array $q, int $languageId): ?string {
        $parts = [];
        $route = (string)($q['route'] ?? '');

        foreach ($q as $key => $value) {
            if ($key === 'path' && $route !== '') {
                foreach (explode('_', (string)$value) as $cat) {
                    $kw = $this->oc3Keyword('category_id=' . (int)$cat, $languageId);

                    if ($kw === null) {
                        return null;
                    }

                    $parts[] = $kw;
                }

                unset($q[$key]);
            } elseif (in_array($key, ['product_id', 'information_id', 'manufacturer_id'], true)) {
                $kw = $this->oc3Keyword($key . '=' . (int)$value, $languageId);

                if ($kw === null) {
                    return null;
                }

                $parts[] = $kw;
                unset($q[$key]);
            }
        }

        if (!$parts) {
            return null;
        }

        unset($q['route']);

        return $this->base . implode('/', $parts) . ($q ? '?' . http_build_query($q) : '');
    }

    private function oc3Keyword(string $query, int $languageId): ?string {
        $kw = $this->db->value("SELECT `keyword` FROM " . $this->db->t('seo_url') . " WHERE `query` = " . $this->db->q($query) . " AND `store_id` = " . $this->storeId
            . " ORDER BY (`language_id` = " . $languageId . ") DESC LIMIT 1", 'keyword');

        return $kw === null || $kw === '' ? null : (string)$kw;
    }

    private function oc4(array $q, int $languageId): ?string {
        $found = [];

        foreach ($q as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $row = $this->db->row("SELECT `keyword`" . ($this->hasSortOrder() ? ", `sort_order`" : ", 0 AS sort_order") . " FROM " . $this->db->t('seo_url')
                . " WHERE `key` = " . $this->db->q((string)$key) . " AND `value` = " . $this->db->q((string)$value) . " AND `store_id` = " . $this->storeId
                . " AND `language_id` = " . $languageId . " LIMIT 1");

            if ($row !== null && (string)$row['keyword'] !== '') {
                $found[] = [(int)$row['sort_order'], count($found), (string)$row['keyword']];
                unset($q[$key]);
            }
        }

        if (!$found) {
            return null;
        }

        usort($found, function ($a, $b) { return [$a[0], $a[1]] <=> [$b[0], $b[1]]; });

        return $this->base . implode('/', array_column($found, 2)) . ($q ? '?' . http_build_query($q) : '');
    }

    private function hasSortOrder(): bool {
        if ($this->sortOrder === null) {
            $this->sortOrder = $this->db->row("SHOW COLUMNS FROM " . $this->db->t('seo_url') . " LIKE 'sort_order'") !== null;
        }

        return $this->sortOrder;
    }

    private function code(int $languageId): string {
        if ($this->codes === null) {
            $this->codes = [];

            foreach ($this->db->rows("SELECT `language_id`, `code` FROM " . $this->db->t('language')) as $r) {
                $this->codes[(int)$r['language_id']] = (string)$r['code'];
            }
        }

        return $this->codes[$languageId] ?? '';
    }
}
