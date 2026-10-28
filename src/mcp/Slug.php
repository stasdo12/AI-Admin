<?php
namespace OcMcp;

/**
 * SEO keywords from names: Ukrainian transliterated by the official system (Resolution of the Cabinet of Ministers
 * No. 55, 2010), Russian letters by the closest equivalents, lower case, words joined by '-', at most 64 characters
 * (OpenCart's own limit), cut at a word boundary.
 */
final class Slug {
    const MAX = 64;

    /** Letters that are written differently at the start of a word (є -> ye, ї -> yi, й -> y, ю -> yu, я -> ya). */
    const INITIAL = ['є' => 'ye', 'ї' => 'yi', 'й' => 'y', 'ю' => 'yu', 'я' => 'ya'];
    const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie', 'ж' => 'zh', 'з' => 'z',
        'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
        'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ь' => '', 'ю' => 'iu', 'я' => 'ia', '’' => '', "'" => '', 'ʼ' => '', '`' => '',
        // Russian letters that Ukrainian does not have
        'ы' => 'y', 'э' => 'e', 'ё' => 'e', 'ъ' => '',
    ];

    public static function make(string $text, int $max = self::MAX): string {
        $s = mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'), 'UTF-8');
        $s = str_replace(['зг'], ['zgh'], $s); // зг -> zgh, to tell it from ж (zh)
        $out = '';
        $len = mb_strlen($s, 'UTF-8');
        $wordStart = true;

        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($s, $i, 1, 'UTF-8');

            if ($wordStart && isset(self::INITIAL[$ch])) {
                $out .= self::INITIAL[$ch];
            } elseif (isset(self::MAP[$ch])) {
                $out .= self::MAP[$ch];
            } else {
                $out .= $ch;
            }

            $wordStart = !preg_match('/[\p{L}\p{N}’\'ʼ`]/u', $ch);
        }

        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $out); // é -> e, ß -> ss for other languages

            if (is_string($ascii) && $ascii !== '') {
                $out = $ascii;
            }
        }

        $out = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($out)), '-');

        if (strlen($out) > $max) {
            $cut = substr($out, 0, $max + 1);
            $pos = strrpos($cut, '-');
            $out = rtrim($pos !== false && $pos > $max / 2 ? substr($cut, 0, $pos) : substr($out, 0, $max), '-');
        }

        return $out;
    }

    /** A keyword the AI sent: kept as given when it is already a clean slug, otherwise made into one. */
    public static function clean(string $keyword): string {
        $k = trim($keyword);

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $k) && strlen($k) <= self::MAX ? $k : self::make($k);
    }
}
