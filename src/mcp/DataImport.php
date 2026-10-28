<?php
namespace OcMcp;

/**
 * CSV import: the file the owner uploaded through a one-time link (create_upload_link kind "csv") is parsed here and
 * turned into the calls of the tools that already know how to write safely - bulk_update_products for existing
 * products, save_content for new ones, manage_customer for customers - all in one journal batch (one rollback).
 *
 * The parser takes any of ; , tab | as the delimiter (whichever the first line has most of), a UTF-8 BOM, quoted
 * fields and CRLF. Columns are matched by common header names in English, Ukrainian and Russian, or by an explicit
 * {"header": "field"} map from the assistant.
 */
final class DataImport {
    const DIR = 'mcp-import';
    const MAX_BYTES = 10485760; // 10 MB
    const MAX_ROWS = 5000;      // the bulk tool's limit
    const ENTITIES = ['products', 'customers'];
    const KEEP_HOURS = 24;      // uploaded files older than this are removed on the next import or upload

    /** field => header names that mean it (compared lower-case, with _ and - as spaces) */
    const SYNONYMS = [
        'products' => [
            'product_id'         => ['product id', 'id', 'id товару', 'id товара'],
            'sku'                => ['sku', 'артикул', 'article', 'арт', 'код', 'code', 'код товару', 'код товара'],
            'model'              => ['model', 'модель'],
            'name'               => ['name', 'назва', 'название', 'товар', 'product', 'product name', 'назва товару', 'название товара', 'найменування', 'наименование'],
            'price'              => ['price', 'ціна', 'цена', 'ціна, грн', 'цена, грн', 'ціна грн', 'цена грн', 'retail', 'роздріб', 'розница'],
            'quantity'           => ['quantity', 'qty', 'кількість', 'количество', 'залишок', 'остаток', 'stock', 'наявність', 'наличие', 'к-сть', 'кол-во'],
            'status'             => ['status', 'статус', 'active', 'активний', 'активный', 'enabled', 'увімкнено', 'включен'],
            'special_price'      => ['special', 'special price', 'акція', 'акция', 'акційна ціна', 'акционная цена', 'sale', 'sale price', 'знижка', 'скидка'],
            'special_date_start' => ['special date start', 'date start', 'акція з', 'акция с', 'start'],
            'special_date_end'   => ['special date end', 'date end', 'акція до', 'акция до', 'end'],
            'manufacturer'       => ['manufacturer', 'brand', 'бренд', 'виробник', 'производитель'],
            'ean'                => ['ean', 'barcode', 'штрихкод', 'штрих-код', 'штрих код'],
            'weight'             => ['weight', 'вага', 'вес'],
            'description'        => ['description', 'опис', 'описание'],
        ],
        'customers' => [
            'email'          => ['email', 'e mail', 'пошта', 'почта', 'mail', 'електронна пошта', 'эл. почта'],
            'firstname'      => ['firstname', 'first name', "ім'я", 'імя', 'имя', 'name'],
            'lastname'       => ['lastname', 'last name', 'прізвище', 'фамилия', 'surname'],
            'telephone'      => ['telephone', 'phone', 'tel', 'телефон', 'тел', 'мобільний', 'мобильный'],
            'customer_group' => ['customer group', 'group', 'група', 'группа'],
            'newsletter'     => ['newsletter', 'розсилка', 'рассылка', 'subscribed', 'підписка', 'подписка'],
            'status'         => ['status', 'статус', 'active', 'активний', 'активный'],
            'company'        => ['company', 'компанія', 'компания', 'фірма', 'фирма'],
            'address_1'      => ['address', 'address 1', 'адреса', 'адрес', 'street', 'вулиця', 'улица'],
            'city'           => ['city', 'місто', 'город'],
            'postcode'       => ['postcode', 'zip', 'індекс', 'индекс', 'поштовий індекс', 'почтовый индекс'],
            'country'        => ['country', 'країна', 'страна'],
            'zone'           => ['zone', 'region', 'state', 'область', 'регіон', 'регион'],
        ],
    ];

    // ------------------------------------------------------------------ files

    public static function dir(Context $c): string {
        return rtrim((string)$c->storageDir, '/') . '/' . self::DIR;
    }

    public static function path(Context $c, int $uploadId): string {
        return self::dir($c) . '/' . $uploadId . '.csv';
    }

    /** Store an uploaded CSV for its link; returns the path. */
    public static function store(string $storageDir, int $uploadId, string $tmpFile): string {
        $dir = rtrim($storageDir, '/') . '/' . self::DIR;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('The storage folder is not writable: ' . $dir);
        }

        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Deny from all\n"); // never served by the web server
        }

        $path = $dir . '/' . $uploadId . '.csv';

        if (!@move_uploaded_file($tmpFile, $path) && !@rename($tmpFile, $path) && !@copy($tmpFile, $path)) {
            throw new \RuntimeException('Could not save the file.');
        }

        self::cleanup($storageDir);

        return $path;
    }

    /** Remove import files older than KEEP_HOURS (a file is also removed right after its import is complete). */
    public static function cleanup(string $storageDir): void {
        $dir = rtrim($storageDir, '/') . '/' . self::DIR;

        foreach ((array)@glob($dir . '/*.csv') as $f) {
            if (is_file($f) && filemtime($f) < time() - self::KEEP_HOURS * 3600) {
                @unlink($f);
            }
        }
    }

    // ------------------------------------------------------------------ parsing

    /** @return array{delimiter:string,headers:array,rows:array,truncated:bool} */
    public static function parse(string $text): array {
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = substr($text, 3);
        }

        $firstLine = strtok($text, "\r\n");
        $firstLine = $firstLine === false ? '' : $firstLine;
        $best = ',';
        $max = -1;

        foreach ([';', ',', "\t", '|'] as $d) {
            $n = substr_count($firstLine, $d);

            if ($n > $max) {
                $max = $n;
                $best = $d;
            }
        }

        $h = fopen('php://temp', 'r+');
        fwrite($h, $text);
        rewind($h);
        $headers = null;
        $rows = [];
        $truncated = false;

        while (($r = Csv::get($h, $best)) !== false) {
            if ($r === [null] || $r === null) {
                continue; // blank line
            }

            $r = array_map(function ($x) { return trim((string)$x); }, $r);

            if ($headers === null) {
                $headers = $r;
                continue;
            }

            if (implode('', $r) === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }

            $rows[] = $r;
        }

        fclose($h);

        return ['delimiter' => $best, 'headers' => $headers ?: [], 'rows' => $rows, 'truncated' => $truncated];
    }

    private static function norm(string $s): string {
        $s = trim(str_replace(['_', '-', '.', ':'], ' ', $s));
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);

        return (string)$s;
    }

    /**
     * Which column holds which field: the explicit map {"header": "field"} first, then the known header names.
     * @return array{fields:array<string,int>,unmapped:array<string>}
     */
    public static function map(array $headers, array $explicit, string $entity): array {
        $known = self::SYNONYMS[$entity] ?? [];
        $normHeaders = array_map([self::class, 'norm'], array_map('strval', $headers));
        $fields = [];
        $used = [];

        foreach ($explicit as $hdr => $field) {
            $field = (string)$field;

            if (!isset($known[$field])) {
                throw new ToolException("columns: \"$hdr\" is mapped to \"$field\", which a $entity import does not set. Fields: " . implode(', ', array_keys($known)) . '. Nothing was changed.');
            }

            $idx = array_search(self::norm((string)$hdr), $normHeaders, true);

            if ($idx === false && is_numeric($hdr)) {
                $idx = (int)$hdr; // a column number instead of a header
            }

            if ($idx !== false && isset($headers[$idx])) {
                $fields[$field] = (int)$idx;
                $used[(int)$idx] = true;
            }
        }

        foreach ($normHeaders as $i => $n) {
            if (isset($used[$i]) || $n === '') {
                continue;
            }

            foreach ($known as $field => $syns) {
                if (isset($fields[$field])) {
                    continue;
                }

                if (in_array($n, array_map([self::class, 'norm'], $syns), true)) {
                    $fields[$field] = $i;
                    $used[$i] = true;
                    break;
                }
            }
        }

        $unmapped = [];

        foreach ($headers as $i => $h) {
            if (!isset($used[$i]) && trim((string)$h) !== '') {
                $unmapped[] = (string)$h;
            }
        }

        return ['fields' => $fields, 'unmapped' => $unmapped];
    }

    public static function cell(array $row, array $fields, string $field): ?string {
        if (!isset($fields[$field])) {
            return null;
        }

        $v = $row[$fields[$field]] ?? null;

        return $v === null ? null : trim((string)$v);
    }

    /** "1", "yes", "так", "да", "true", "on", "+" -> true; "0", "no", "ні", "нет", "false", "off", "-" -> false; anything else null. */
    public static function flag(?string $v): ?bool {
        if ($v === null || $v === '') {
            return null;
        }

        $n = self::norm($v);

        if (in_array($n, ['1', 'yes', 'y', 'так', 'да', 'true', 'on', '+', 'enabled', 'увімкнено', 'включен', 'active'], true)) {
            return true;
        }

        if (in_array($n, ['0', 'no', 'n', 'ні', 'нет', 'false', 'off', '-', 'disabled', 'вимкнено', 'выключен', 'inactive'], true)) {
            return false;
        }

        return null;
    }

    /** "1 234,50" / "1234.50" / "1 234.50 грн" -> 1234.5; null when there is no number. */
    public static function number(?string $v): ?float {
        if ($v === null) {
            return null;
        }

        $s = preg_replace('/[^\d,.\-]/u', '', str_replace(["\xc2\xa0", ' '], '', $v));

        if ($s === '' || $s === '-' || $s === null) {
            return null;
        }

        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
            $s = str_replace(',', '', $s); // 1,234.50
        } else {
            $s = str_replace(',', '.', $s); // 1234,50
        }

        return is_numeric($s) ? (float)$s : null;
    }

    // ------------------------------------------------------------------ plans

    /**
     * Rows of a product file split into updates (bulk_update_products rows) and creates (save_content items).
     * @return array{by:string,updates:array,creates:array,skipped:array,warnings:array}
     */
    public static function planProducts(Context $c, array $rows, array $fields, string $by, bool $createMissing): array {
        $db = $c->db;

        if ($by === '') {
            $by = isset($fields['sku']) ? 'sku' : (isset($fields['model']) ? 'model' : (isset($fields['product_id']) ? 'product_id' : ''));
        }

        if ($by === '' || !isset($fields[$by])) {
            throw new ToolException('No key column: the file needs a column with the SKU (артикул), the model or the product id to find products by (or map one with columns).');
        }

        $keys = [];

        foreach ($rows as $r) {
            $k = self::cell($r, $fields, $by);

            if ($k !== null && $k !== '') {
                $keys[$k] = true;
            }
        }

        $existing = []; // key => product_id

        foreach (array_chunk(array_keys($keys), 500) as $chunk) {
            if ($by === 'product_id') {
                $sql = "SELECT p.`product_id` AS k, p.`product_id` FROM " . $db->t('product') . " p WHERE p.`product_id` IN (" . $db->ints($chunk) . ")";
            } else {
                $expr = $by === 'sku' ? $c->skuExpr('p') : 'p.`model`';
                $sql = "SELECT " . $expr . " AS k, p.`product_id` FROM " . $db->t('product') . " p WHERE " . $expr . " IN (" . implode(', ', array_map(function ($x) use ($db) { return $db->q((string)$x); }, $chunk)) . ")";
            }

            foreach ($db->rows($sql) as $r) {
                $existing[(string)$r['k']] = (int)$r['product_id'];
            }
        }

        $hasSku = $c->hasColumn('product', 'sku');
        $manufacturers = null;
        $updates = [];
        $creates = [];
        $skipped = [];
        $warnings = [];

        foreach ($rows as $i => $r) {
            $line = $i + 2; // 1-based, after the header
            $key = self::cell($r, $fields, $by);

            if ($key === null || $key === '') {
                $skipped[] = ['row' => $line, 'reason' => 'no ' . $by];
                continue;
            }

            $price = self::number(self::cell($r, $fields, 'price'));
            $qty = self::number(self::cell($r, $fields, 'quantity'));
            $status = self::flag(self::cell($r, $fields, 'status'));
            $special = self::number(self::cell($r, $fields, 'special_price'));

            if (isset($existing[$key])) {
                $u = [$by => $by === 'product_id' ? (int)$key : $key];

                if ($price !== null) {
                    $u['price'] = $price;
                }

                if ($qty !== null) {
                    $u['quantity'] = (int)$qty;
                }

                if ($status !== null) {
                    $u['status'] = $status;
                }

                if ($special !== null) {
                    $u['special_price'] = $special;

                    foreach (['special_date_start', 'special_date_end'] as $d) {
                        $v = self::cell($r, $fields, $d);

                        if ($v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                            $u[$d] = $v;
                        }
                    }
                }

                if (count($u) === 1) {
                    $skipped[] = ['row' => $line, 'reason' => 'nothing to change (no price, quantity, status or special in the row)'];
                    continue;
                }

                $updates[] = $u;
                continue;
            }

            if (!$createMissing) {
                $skipped[] = ['row' => $line, 'reason' => 'no product with ' . $by . ' "' . $key . '"'];
                continue;
            }

            $name = self::cell($r, $fields, 'name');

            if ($name === null || $name === '') {
                $skipped[] = ['row' => $line, 'reason' => 'new product without a name (' . $by . ' "' . $key . '")'];
                continue;
            }

            if ($price === null) {
                $skipped[] = ['row' => $line, 'reason' => 'new product "' . $name . '" without a price'];
                continue;
            }

            $texts = ['name' => $name];
            $desc = self::cell($r, $fields, 'description');

            if ($desc !== null && $desc !== '') {
                $texts['description'] = $desc;
            }

            $data = ['price' => $price, 'quantity' => $qty !== null ? (int)$qty : 0];
            $model = self::cell($r, $fields, 'model');
            $sku = self::cell($r, $fields, 'sku');
            $data['model'] = $model !== null && $model !== '' ? $model : $key;

            if ($hasSku && $sku !== null && $sku !== '') {
                $data['sku'] = $sku;
            }

            if ($status !== null) {
                $data['status'] = $status;
            }

            $ean = self::cell($r, $fields, 'ean');

            if ($ean !== null && $ean !== '' && $c->hasColumn('product', 'ean')) {
                $data['ean'] = $ean;
            }

            $weight = self::number(self::cell($r, $fields, 'weight'));

            if ($weight !== null) {
                $data['weight'] = $weight;
            }

            $brand = self::cell($r, $fields, 'manufacturer');

            if ($brand !== null && $brand !== '') {
                if ($manufacturers === null) {
                    $manufacturers = [];

                    foreach ($db->rows("SELECT `manufacturer_id`, `name` FROM " . $db->t('manufacturer')) as $m) {
                        $manufacturers[self::norm((string)$m['name'])] = (int)$m['manufacturer_id'];
                    }
                }

                $mid = $manufacturers[self::norm($brand)] ?? 0;

                if ($mid) {
                    $data['manufacturer_id'] = $mid;
                } else {
                    $warnings['unknown manufacturer "' . $brand . '"'] = true;
                }
            }

            $creates[] = ['row' => $line, 'entity' => 'product', 'texts' => [(string)ContentEntities::writeLanguage($c) => $texts], 'data' => $data];
        }

        return ['by' => $by, 'updates' => $updates, 'creates' => $creates, 'skipped' => $skipped, 'warnings' => array_keys($warnings)];
    }

    /**
     * Rows of a customer file as manage_customer items: an existing e-mail is updated, a new one created.
     * @return array{items:array,skipped:array}
     */
    public static function planCustomers(Context $c, array $rows, array $fields): array {
        $db = $c->db;

        if (!isset($fields['email'])) {
            throw new ToolException('No e-mail column: customers are found and created by e-mail (map one with columns).');
        }

        $emails = [];

        foreach ($rows as $r) {
            $e = self::cell($r, $fields, 'email');

            if ($e !== null && $e !== '') {
                $emails[strtolower($e)] = true;
            }
        }

        $existing = [];

        foreach (array_chunk(array_keys($emails), 500) as $chunk) {
            foreach ($db->rows("SELECT `customer_id`, LOWER(`email`) AS e FROM " . $db->t('customer') . " WHERE LOWER(`email`) IN (" . implode(', ', array_map(function ($x) use ($db) { return $db->q((string)$x); }, $chunk)) . ")") as $r) {
                $existing[(string)$r['e']] = (int)$r['customer_id'];
            }
        }

        $items = [];
        $skipped = [];
        $seen = [];

        foreach ($rows as $i => $r) {
            $line = $i + 2;
            $email = self::cell($r, $fields, 'email');

            if ($email === null || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = ['row' => $line, 'reason' => 'no valid e-mail'];
                continue;
            }

            if (isset($seen[strtolower($email)])) {
                $skipped[] = ['row' => $line, 'reason' => 'e-mail repeats an earlier row: ' . $email];
                continue;
            }

            $seen[strtolower($email)] = true;
            $it = ['row' => $line];

            foreach (['firstname', 'lastname', 'telephone', 'customer_group'] as $f) {
                $v = self::cell($r, $fields, $f);

                if ($v !== null && $v !== '') {
                    $it[$f] = $v;
                }
            }

            foreach (['newsletter', 'status'] as $f) {
                $v = self::flag(self::cell($r, $fields, $f));

                if ($v !== null) {
                    $it[$f] = $v;
                }
            }

            if (isset($existing[strtolower($email)])) {
                if (count($it) === 1) {
                    $skipped[] = ['row' => $line, 'reason' => 'nothing to change for ' . $email];
                    continue;
                }

                $it['action'] = 'update';
                $it['customer_id'] = $existing[strtolower($email)];
            } else {
                if (empty($it['firstname']) || empty($it['lastname'])) {
                    $skipped[] = ['row' => $line, 'reason' => 'new customer ' . $email . ' needs a first and a last name'];
                    continue;
                }

                $it['action'] = 'create';
                $it['email'] = $email;
                $addr = [];

                foreach (['company', 'address_1', 'city', 'postcode', 'country', 'zone'] as $f) {
                    $v = self::cell($r, $fields, $f);

                    if ($v !== null && $v !== '') {
                        $addr[$f] = $v;
                    }
                }

                if (!empty($addr['address_1']) && !empty($addr['city']) && !empty($addr['country'])) {
                    $addr['default'] = true;
                    $it['addresses'] = [$addr];
                }
            }

            $items[] = $it;
        }

        return ['items' => $items, 'skipped' => $skipped];
    }
}
