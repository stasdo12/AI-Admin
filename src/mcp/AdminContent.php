<?php
namespace OcMcp;

/** Admin side of the "Prompts" tab, shared by the OpenCart 3 and 4 controllers: data for the page and its AJAX actions. */
final class AdminContent {
    const JSON = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    /** @param callable $config (string $key) => mixed */
    public static function data(Db $db, callable $config, string $uiLang, string $systemDir): array {
        $lib = new PromptLibrary($db, PromptLibrary::lang($uiLang));
        $settings = ContentPolicy::normalize(call_user_func($config, 'module_mcp_server_content') ?: []);

        return [
            'content_json' => json_encode($settings, self::JSON),
            'store_languages' => array_values(array_map(function ($l) { return ['code' => $l['code'], 'name' => $l['name']]; }, ContentEntities::languages($db))),
            'prompts_json' => json_encode(['prompts' => $lib->all(true), 'sections' => $lib->sections()], self::JSON),
        ];
    }


    /** Owner's content rules from the settings form (JSON of the switches), cleaned. */
    public static function content(string $json): array {
        $raw = json_decode(html_entity_decode($json, ENT_QUOTES, 'UTF-8'), true); // OpenCart escapes request data

        return ContentPolicy::normalize(is_array($raw) ? $raw : []);
    }

    /** save | create | reset | delete of a prompt, section_add | section_delete; answers the fresh list. */
    public static function prompts(Db $db, string $uiLang, array $post): array {
        $lib = new PromptLibrary($db, PromptLibrary::lang($uiLang));
        $op = (string)($post['op'] ?? '');
        $name = strtolower(trim((string)($post['name'] ?? '')));
        $saved = '';

        try {
            switch ($op) {
                case 'save':
                case 'create':
                    $saved = $lib->save($op === 'create' ? '' : $name, (string)($post['title'] ?? ''), (string)($post['description'] ?? ''), (string)($post['body'] ?? ''),
                        !isset($post['enabled']) || !empty($post['enabled']), (string)($post['section'] ?? ''));
                    break;
                case 'reset':
                    $lib->reset($name);
                    break;
                case 'delete':
                    $lib->delete($name);
                    break;
                case 'section_add':
                    $saved = $lib->addSection((string)($post['title'] ?? ''));
                    break;
                case 'section_delete':
                    $lib->deleteSection((string)($post['section'] ?? ''));
                    break;
                default:
                    return ['error' => 'unknown operation'];
            }
        } catch (ToolException $e) {
            return ['error' => $e->getMessage()];
        }

        return ['ok' => true, 'saved' => $saved, 'prompts' => $lib->all(true), 'sections' => $lib->sections()];
    }
}
