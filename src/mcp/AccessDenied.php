<?php
namespace OcMcp;

/**
 * One format for every "no access" answer, so the AI can tell the store owner exactly what is blocked and where to allow it.
 *
 * Text (for the model and the user) + structuredContent:
 *   {error: "access_denied", kind: table|field|mode|locked, action, section, subsection, tables, fields,
 *    current, required, can_be_granted, how_to_grant}
 */
final class AccessDenied {
    const LEVEL_NAMES = ['none' => 'Closed', 'read' => 'View', 'write' => 'Edit', 'full' => 'Full'];
    const MODE_NAMES = ['read_only' => 'View only (no section allows editing)', 'manage' => 'Editing allowed in at least one section'];
    const SETTINGS_PATH = 'OpenCart admin → Extensions → Extensions → Modules → MCP Server';

    const NAMES = [
        'catalog' => 'Catalog', 'sales' => 'Sales', 'customers' => 'Customers', 'marketing' => 'Marketing', 'reports' => 'Reports', 'system' => 'System',
        'design' => 'Design', 'extensions' => 'Extensions', 'other' => 'Other modules', 'plans' => 'Recurring profiles / subscription plans', 'seo' => 'SEO URLs',
        'information' => 'Information pages', 'custom_fields' => 'Custom fields', 'voucher_themes' => 'Voucher themes', 'vouchers' => 'Gift vouchers', 'carts' => 'Shopping carts',
        'groups' => 'Customer groups', 'approvals' => 'Customer approvals', 'gdpr' => 'GDPR', 'users' => 'Administrators', 'api' => 'API', 'service' => 'Service tables',
        'subscriptions' => 'Recurring orders / subscriptions',
        'localisation' => 'Localisation', 'locations' => 'Store locations', 'stock_statuses' => 'Stock statuses', 'order_statuses' => 'Order statuses', 'subscription_statuses' => 'Subscription statuses',
        'return_lists' => 'Return statuses, actions and reasons', 'geo_zones' => 'Geo zones', 'length_classes' => 'Length classes', 'weight_classes' => 'Weight classes', 'address_formats' => 'Address formats', 'theme' => 'Theme editor', 'translation' => 'Language editor', 'other.other' => 'Tables of third-party modules',
    ];

    private static function name(string $key): string {
        return self::NAMES[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /** Where a table lives in the admin "Access" tab. */
    public static function where(string $table): array {
        $key = AccessMap::subsectionOf($table);
        list($section, $sub) = explode('.', $key);

        return ['key' => $key, 'section' => self::name($section), 'subsection' => $key === 'other.other' ? self::name('other.other') : self::name($sub)];
    }

    /** Why a table can never be opened (or '' if it can). */
    public static function lockReason(string $table, string $required): string {
        if (!AccessMap::isKnown($table)) {
            return 'it belongs to a third-party module; tables of other modules are closed for the AI in this version and are not on the Access tab';
        }

        if (array_search($required, TablePolicy::LEVELS, true) <= array_search(TablePolicy::maxLevel($table), TablePolicy::LEVELS, true)) {
            return '';
        }

        if (TablePolicy::isSensitive($table)) {
            return 'it holds credentials or secrets (passwords, sessions, API keys, settings with secrets) and is never available to the AI';
        }

        $section = explode('.', AccessMap::subsectionOf($table))[0];

        if ($section === 'design' || $section === 'extensions') {
            return 'design and extensions are locked so that the AI can never break a layout or a module';
        }

        return 'it is part of the audit log or the module itself and is never writable';
    }

    /**
     * A table- or field-level denial.
     * @param string $action  human description, e.g. "change product prices"
     * @param string[] $fields  columns involved (optional)
     */
    public static function table(string $table, string $action, string $current, string $required, array $fields = [], string $kind = 'table'): array {
        $w = self::where($table);
        $lock = self::lockReason($table, $required);
        $how = '';

        if ($lock === '' && $kind === 'table' && array_search($current, TablePolicy::LEVELS, true) !== false && array_search($current, TablePolicy::LEVELS, true) >= array_search($required, TablePolicy::LEVELS, true)) {
            // the level is already enough ("Current: Full. Needed: Full" read as a contradiction): the table has its own tool
            $lock = 'the access level is already enough; this table is not changed row by row (manage_records / SQL), only through its own tool';
        }

        if ($lock === '') {
            $how = self::SETTINGS_PATH . ' → Access → ' . $w['section'] . ' → ' . $w['subsection'] . ' → set "' . self::LEVEL_NAMES[$required] . '"';

            if ($kind === 'field' && $fields) {
                $how .= ($required === 'read' ? '' : ' (if not set yet)') . ', then open "Details" and tick "' . ($required === 'read' ? 'View' : 'Edit') . '" for: ' . implode(', ', $fields);
            }

            $how .= '. Save the settings and repeat the request.';
        }

        return [
            'error'          => 'access_denied',
            'kind'           => $lock !== '' ? 'locked' : $kind,
            'action'         => $action,
            'section'        => $w['section'],
            'subsection'     => $w['subsection'],
            'tables'         => [$table],
            'fields'         => array_values($fields),
            'current'        => self::LEVEL_NAMES[$current] ?? $current,
            'required'       => self::LEVEL_NAMES[$required] ?? $required,
            'can_be_granted' => $lock === '',
            'how_to_grant'   => $lock === '' ? $how : 'This cannot be enabled: ' . $lock . '.',
        ];
    }

    /** The global access mode does not include the tool. */
    public static function mode(string $tool, string $current, string $required): array {
        return [
            'error'          => 'access_denied',
            'kind'           => 'mode',
            'action'         => 'use the tool ' . $tool,
            'section'        => 'Access',
            'subsection'     => 'Access mode',
            'tables'         => [],
            'fields'         => [],
            'current'        => self::MODE_NAMES[$current] ?? $current,
            'required'       => self::MODE_NAMES[$required] ?? $required,
            'can_be_granted' => true,
            'how_to_grant'   => self::SETTINGS_PATH . ' → Access → open the section the change belongs to → set "Edit" (or the preset "Recommended"), then Save. Access mode follows these levels automatically and the same chat can go on.',
        ];
    }

    /** The hourly budget of personal-data rows is used up. */
    public static function piiLimit(int $limit, int $minutes): array {
        return ['error' => 'access_denied', 'kind' => 'limit', 'action' => 'receive more personal data (customer names, e-mails, phones, addresses) this hour',
                'section' => 'Settings', 'subsection' => 'Personal data rows per hour', 'tables' => [], 'fields' => [],
                'current' => $limit . ' rows per hour, used up', 'required' => 'more rows', 'can_be_granted' => true,
                'how_to_grant' => 'Wait about ' . $minutes . ' min for the next hour, ask for totals instead of customer lists, or raise the limit: ' . self::SETTINGS_PATH . ' → Settings → Limits → "Personal data rows per hour" (now ' . $limit . ').'];
    }

    /** A statement whose target could not be determined (not a permission problem of a specific table). */
    public static function unclear(string $message): array {
        return ['error' => 'access_denied', 'kind' => 'unclear', 'action' => 'run this SQL statement', 'section' => '', 'subsection' => '', 'tables' => [], 'fields' => [],
                'current' => '', 'required' => '', 'can_be_granted' => false, 'how_to_grant' => $message];
    }

    public static function text(array $i): string {
        $lines = ['ACCESS DENIED: the store owner has not allowed the AI to ' . $i['action'] . '.'];

        if ($i['section'] !== '') {
            $where = $i['section'] . ($i['subsection'] !== '' ? ' › ' . $i['subsection'] : '');
            $lines[] = 'Where: ' . $where . ($i['tables'] ? ' (database: ' . implode(', ', $i['tables']) . ')' : '') . ($i['fields'] ? '; fields: ' . implode(', ', $i['fields']) : '') . '.';
        }

        if (($i['current'] !== '' || $i['required'] !== '') && $i['current'] !== $i['required']) {
            $lines[] = 'Current access: ' . $i['current'] . '. Needed: ' . $i['required'] . '.';
        }

        $lines[] = ($i['can_be_granted'] ? 'How to allow it: ' : '') . $i['how_to_grant'];
        $lines[] = 'Tell the user exactly this. Do not try to get around it with other tools or SQL.';

        return implode("\n", $lines);
    }

    public static function result(array $i): ToolResult {
        $r = ToolResult::text(self::text($i));
        $r->structured = $i;
        $r->isError = true;

        return $r;
    }
}
