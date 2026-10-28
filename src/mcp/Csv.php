<?php
namespace OcMcp;

/**
 * CSV as spreadsheets and other programs read it (RFC 4180): a quote inside a value is doubled, a backslash is an
 * ordinary character. PHP's fputcsv / fgetcsv treat \" as an escape by default, which split a log row with
 * {"description":"<p class=\"x\">"} into 114 columns; an empty escape is only accepted from PHP 7.4.
 */
final class Csv {
    /** Write one row. */
    public static function put($handle, array $fields, string $delimiter = ','): void {
        $cells = [];

        foreach ($fields as $v) {
            $v = (string)$v;
            $cells[] = $v === '' || strpbrk($v, $delimiter . "\"\r\n") === false && trim($v) === $v ? $v : '"' . str_replace('"', '""', $v) . '"';
        }

        fwrite($handle, implode($delimiter, $cells) . "\n");
    }

    /** Read one row (false at the end); a backslash before a quote has no special meaning. */
    public static function get($handle, string $delimiter = ',') {
        // PHP 7.4+: no escape character at all; older PHP: one that never appears in text
        return fgetcsv($handle, 0, $delimiter, '"', PHP_VERSION_ID >= 70400 ? '' : "\0");
    }
}
