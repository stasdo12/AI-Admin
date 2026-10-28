<?php
namespace OcMcp;

/**
 * Field groups exactly as the store's own admin form shows them: the tabs of e.g. catalog/category_form.twig
 * (General, Data, SEO, Design ...) with their fields in form order, each mapped to the table + column it is stored in.
 * Read from the store's templates at run time, so OpenCart 3, 4 and ocStore forms all come out as the owner knows them.
 * A field carries the form's own star (required): class="form-group required" in 3.x, class="row mb-3 required" or
 * <th class="required"> over a column of rows in 4.x. A class written by twig or javascript ({% if option.required %},
 * option['required'] ? ' required') is a star of a product option or custom field, not of the form, and is left alone.
 *
 * Input names map to storage like this:
 *   category_description[..][name]                 -> category_description.name
 *   product_option[..][product_option_value][..][price] -> product_option_value.price
 *   product_attribute[..][product_attribute_description][..][text] -> product_attribute.text (nested name is not a table)
 *   category_store[] / product_category[] / product_related[] -> category_to_store.store_id / product_to_category.category_id ...
 *   category_seo_url[..][..]                        -> seo_url.keyword
 *   parent_id, image, status ...                    -> the column of the sub-section's main table that has it
 */
final class FormLayout {
    /**
     * @param string $twig    template source
     * @param array  $columns table => column names, for the tables of this admin sub-section (+ seo_url)
     * @param array  $strings language strings of the form (tab_*, entry_*)
     * @return array list of [title, tab (the pane id: tab-general ...), fields => [[table, column, label, technical, required]]]
     */
    public static function parse(string $twig, array $columns, array $strings): array {
        $twig = preg_replace('/\{#.*?#\}/s', '', $twig);
        $titles = [];

        if (preg_match_all('/href="#(tab-[a-z0-9_-]+)"[^>]*>\s*(?:<i[^>]*><\/i>\s*)?\{\{\s*(\w+)\s*\}\}/i', $twig, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $titles[$x[1]] = self::text($x[2], $strings);
            }
        }

        // pane boundaries in document order
        preg_match_all('/id="(tab-[a-z0-9_-]+)"/i', $twig, $panes, PREG_OFFSET_CAPTURE);
        $bounds = [];

        foreach ($panes[1] as $p) {
            if (isset($titles[$p[0]])) {
                $bounds[] = [$p[0], $p[1]];
            }
        }

        // panes opened by a loop (3.x customer addresses: id="tab-address{{ address_row }}") take the tab_* string of their stem
        preg_match_all('/id="(tab-[a-z0-9_-]+?)\{\{/i', $twig, $loops, PREG_OFFSET_CAPTURE);

        foreach ($loops[1] as $p) {
            $stem = 'tab_' . str_replace('-', '_', substr($p[0], 4));

            if (!isset($titles[$p[0]]) && self::text($stem, $strings) !== '') {
                $titles[$p[0]] = self::text($stem, $strings);
                $bounds[] = [$p[0], $p[1]];
            }
        }

        usort($bounds, function ($a, $b) { return $a[1] <=> $b[1]; });

        if (!$bounds) {
            $bounds[] = ['tab-general', 0];
            $titles['tab-general'] = self::text('tab_general', $strings) ?: 'General';
        }

        $groups = [];
        $seen = [];
        $main = array_keys($columns)[0] ?? '';

        // the record the form edits is the one whose names it writes: filter_group_description[..][name] makes filter_group
        // the main table of the 3.x filter form, so its plain sort_order is the group's, not a filter's
        if (preg_match('/name="([a-z0-9_]+)_description\[/', $twig, $dm) && isset($columns[$dm[1]])) {
            $main = $dm[1];
        }

        foreach ($bounds as $i => $b) {
            if ($b[0] === 'tab-design' || $b[0] === 'tab-report') {
                continue; // layout overrides belong to Design, which the AI never changes
            }

            $from = $b[1];
            $to = $bounds[$i + 1][1] ?? strlen($twig);
            $chunk = substr($twig, $from, $to - $from);
            $fields = [];

            preg_match_all('/\{\{\s*(entry_\w+)\s*\}\}|<(?:input|select|textarea)\b[^>]*?\bname="([^"]+)"[^>]*>/s', $chunk, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $stars = self::stars($chunk);
            $lastLabel = '';
            $prevEnd = 0; // where the previous token ended: the star stands between it and the label (or field) it marks
            $run = [];        // the labels since the last field, each with its star: one label marks every field after it, the head of a table marks its column
            $runIdx = 0;      // fields stored under those labels (what the rows of a table show, in the order of its head)
            $sinceLabel = 0;  // field tags since the last label: the next label starts a new run

            foreach ($tokens as $tk) {
                $start = $tk[0][1];
                $end = $start + strlen($tk[0][0]);
                $entry = $tk[1][0] ?? '';
                $name = $tk[2][0] ?? '';

                if ($entry !== '' && $name === '') {
                    $lastLabel = $entry;

                    if ($sinceLabel) {
                        $run = [];
                        $runIdx = 0;
                        $sinceLabel = 0;
                    }

                    $run[] = self::starBetween($stars, $prevEnd, $start);
                    $prevEnd = $end;
                    continue;
                }

                $starred = self::starBetween($stars, $prevEnd, $start) || (count($run) === 1 ? $run[0] : ($run[$runIdx] ?? false));
                $prevEnd = $end;
                $sinceLabel++;
                $map = self::mapName($name, $columns, $main);

                if ($map === null) {
                    continue; // e.g. the visible text box of an autocomplete: keep its label for the real field after it
                }

                $near = $lastLabel;
                $lastLabel = ''; // a label belongs to the first stored field after it only

                [$table, $column] = $map;
                $key = $table . '.' . $column;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                // the column's own label first (entry_price for price), then the label written right before the field
                $own = self::text('entry_' . $column, $strings) ?: self::text('column_' . $column, $strings);

                if (substr($column, -3) === '_id') {
                    // a reference: named after what it points to (attribute_id -> entry_attribute, subscription_plan_id -> entry_subscription);
                    // the label written before it only as a fallback: in repeating rows it is the last column header (attribute_id read "Text")
                    $stem = substr($column, 0, -3);
                    $named = self::text('entry_' . $stem, $strings) ?: (strpos($stem, '_') !== false ? (self::text('entry_' . substr($stem, 0, (int)strrpos($stem, '_')), $strings) ?: (strpos($name, '[') !== false ? self::text('entry_' . substr($stem, (int)strrpos($stem, '_') + 1), $strings) : '')) : ''); // in a repeating row: tax_rate_id -> entry_rate
                    $label = $named !== '' ? $named : ($near !== '' ? (self::text($near, $strings) ?: $own) : $own);
                } elseif ($column === 'name' && $near !== '' && $near !== 'entry_name' && !preg_match("/\\[(?:\\{\\{\\s*\\w*row\\s*\\}\\}|'\\s*\\+\\s*\\w*row\\s*\\+\\s*')\\]/", $name)) {
                    // the name of a group edited on its own form: the label before it says whose ("Filter Group Name", not the generic "Filter Name")
                    $label = self::text($near, $strings) ?: $own;
                } else {
                    // the column's own label first (entry_price for price): in repeating rows the label before a field is a column header
                    $label = $own ?: ($near !== '' ? self::text($near, $strings) : '');
                }

                if ($label === '') {
                    $label = AccessMap::label($column, $strings);
                }

                // technical = an internal id (row ids, master_id ...), not a hidden input: OpenCart 4 keeps image, status and
                // autocomplete values (parent_id) in hidden inputs, and those are real fields of the form
                $technical = AccessMap::isComputed($table, $column) || $column === $table . '_id' || preg_match('/(^|_)id$/', $column) && !preg_match('/^(manufacturer|tax_class|stock_status|length_class|weight_class|customer_group|order_status|parent|country|zone|store|category|filter|download|related|option_value|attribute|subscription_plan|language|currency|return_reason|return_action|return_status|voucher_theme|product|geo_zone|tax_rate|address_format)_id$/', $column);

                if (!$technical) {
                    $runIdx++;
                }

                $fields[] = ['table' => $table, 'column' => $column, 'label' => rtrim(trim(strip_tags(html_entity_decode($label, ENT_QUOTES, 'UTF-8'))), ': '), // ocStore labels end with a colon
                             'technical' => $technical, 'required' => $starred && !$technical];
            }

            $visible = array_filter($fields, function ($f) { return !$f['technical']; });

            if ($visible) {
                $groups[] = ['title' => $titles[$b[0]] ?? $b[0], 'tab' => preg_replace('/\{\{.*$/', '', $b[0]), 'fields' => $fields];
            }
        }

        return $groups;
    }

    /**
     * Offsets of the form's own stars: a plain class token "required". Twig and javascript expressions inside the class
     * ({% if custom_field.required %}, option['required'] ? ' required' : '') are stars of a record, not of the form.
     * @return int[]
     */
    public static function stars(string $chunk): array {
        preg_match_all('/class="[^"\'{}]*\brequired\b[^"\'{}]*"/', $chunk, $m, PREG_OFFSET_CAPTURE);

        return array_map(function ($x) { return $x[1]; }, $m[0]);
    }

    private static function starBetween(array $stars, int $from, int $to): bool {
        foreach ($stars as $at) {
            if ($at >= $from && $at < $to) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0:string,1:string}|null table, column */
    public static function mapName(string $name, array $columns, string $main): ?array {
        // drop twig / javascript index expressions: [{{ x }}], [' + row + '], [0]
        $n = preg_replace("/\\[(?:\\{\\{[^}]*\\}\\}|'[^']*'|\\d+|)\\]/", '[]', $name);
        $n = preg_replace('/\{\{[^}]*\}\}/', '', $n);

        if (!preg_match('/^([a-z0-9_]+)((?:\[[a-z0-9_]*\])*)$/', $n, $m)) {
            return null;
        }

        $base = $m[1];
        preg_match_all('/\[([a-z0-9_]*)\]/', $m[2], $parts);
        $words = array_values(array_filter($parts[1], function ($p) { return $p !== ''; }));
        $has = function (string $t, string $c) use ($columns) { return isset($columns[$t]) && in_array($c, $columns[$t], true); };

        if (substr($base, -8) === '_seo_url' && $m[2] !== '' && isset($columns['seo_url'])) { // product_seo_url[..][..], not the config_seo_url switch
            return ['seo_url', 'keyword'];
        }

        if (!$words) {
            if ($m[2] === '') {
                // plain column of the main table (or of another table of the section that has it)
                if ($has($main, $base)) {
                    return [$main, $base];
                }

                foreach ($columns as $t => $cols) {
                    if (in_array($base, $cols, true) && $t !== 'seo_url') {
                        return [$t, $base];
                    }
                }

                if ($main === 'store' && strpos($base, 'config_') === 0 && $has('store', substr($base, 7))) {
                    return ['store', substr($base, 7)]; // the store form writes the store's own row as config_name, config_url, config_ssl
                }

                return null;
            }

            // relation list: category_store[] -> category_to_store.store_id, product_related[] -> product_related.related_id
            // the owner may be one word or more: category_store -> category_to_store, tax_rate_customer_group -> tax_rate_to_customer_group
            $splits = [];

            for ($pos = strpos($base, '_'); $pos !== false; $pos = strpos($base, '_', $pos + 1)) {
                $splits[] = [substr($base, 0, $pos), substr($base, $pos + 1)];
            }

            foreach ($splits as [$owner, $what]) {
                if (isset($columns[$owner . '_to_' . $what])) {
                    break;
                }
            }

            if (!$splits) {
                return null;
            }

            foreach ([$owner . '_to_' . $what, $base] as $t) {
                if (!isset($columns[$t])) {
                    continue;
                }

                foreach ([$what . '_id', $what] as $c) {
                    if ($has($t, $c)) {
                        return [$t, $c];
                    }
                }

                foreach ($columns[$t] as $c) {
                    if (substr($c, -3) === '_id' && $c !== $owner . '_id') {
                        return [$t, $c];
                    }
                }
            }

            return null;
        }

        $column = end($words);
        $table = $base;

        foreach (array_slice($words, 0, -1) as $w) {
            if (isset($columns[$w])) {
                $table = $w; // nested real table: product_option[..][product_option_value][..][price]
            }
        }

        if ($has($table, $column)) {
            return [$table, $column];
        }

        if ($has($base, $column)) {
            return [$base, $column];
        }

        return null;
    }

    private static function text(string $key, array $strings): string {
        return isset($strings[$key]) && is_string($strings[$key]) ? $strings[$key] : '';
    }
}
