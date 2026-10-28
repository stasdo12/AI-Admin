<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\SettingsPolicy;
use OcMcp\Tool;
use OcMcp\ToolResult;

final class GetSettings extends Tool {
    public function name(): string {
        return 'get_settings';
    }

    public function tables(): array {
        return ['setting'];
    }

    public function describe(): array {
        return [
            'title'       => 'Read store settings',
            'description' => 'Store settings (config_*). Settings of extensions (payment, shipping, theme, order totals, other modules) are never shown. Secrets are never returned, owner-hidden fields removed; each setting says if update_settings may change it. filter: part of the key ("stock", "meta").',
            'inputSchema' => self::schema([
                'filter'   => self::prop('string', 'Substring to match against setting keys (case-insensitive).'),
                'store_id' => self::prop('integer', 'Store ID (default 0 = default store).'),
            ]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $db = $c->db;
        $storeId = (int)$this->int($a, 'store_id', 0, 0);
        $filter = strtolower((string)$this->str($a, 'filter', ''));
        $policy = $c->policy();
        $hidden = $policy->hiddenColumns('setting');
        $writable = $policy->level('setting') === 'write' || $policy->level('setting') === 'full';

        $out = [];

        foreach ($db->rows("SELECT `key`, `value`, `serialized` FROM " . $db->t('setting') . " WHERE `store_id` = " . $storeId . " AND " . SettingsPolicy::codeSql($db) . " ORDER BY `code` = 'config' DESC, `code`, `key`") as $row) {
            $key = (string)$row['key'];

            if (SettingsPolicy::isSecret($key) || in_array($key, $hidden, true) || ($filter !== '' && strpos(strtolower($key), $filter) === false)) {
                continue;
            }

            $value = $row['value'];

            if (!empty($row['serialized'])) {
                $decoded = json_decode((string)$value, true);
                $value = $decoded !== null ? $decoded : $value;
            }

            $out[$key] = ['value' => $value, 'editable' => $writable && SettingsPolicy::canEditKey($key) && $policy->canWrite('setting', [$key])];
        }

        return ToolResult::data(['store_id' => $storeId, 'count' => count($out), 'settings' => $out]);
    }
}
