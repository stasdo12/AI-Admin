<?php
namespace OcMcp;

/**
 * The module's admin page, shared by OpenCart 3 and 4: the settings page data, the save, and every AJAX action
 * (History, request log, connections, prompts, content rules, license, address check). The version's controller
 * only checks the request method, calls one of these and sends the answer; what differs between the versions
 * (link format, route separator, admin language setting, SEO alias table) comes in through the constructor.
 * $oc is the OpenCart controller: its registry objects (db, config, request, language, user, load, session ...) are read through it.
 */
final class AdminPanel {
    /** @var object OpenCart controller */
    private $oc;
    /** @var string */
    private $route;
    /** @var string route method separator */
    private $sep;
    /** @var callable (string $method) => url of this module's action */
    private $actionLink;
    /** @var callable (string $route, string $args) => url */
    private $plainLink;
    /** @var string config key of the admin language */
    private $adminLanguageKey;
    /** @var callable (string $urlSecret) => bool, writes the SEO aliases of the endpoint and OAuth documents */
    private $syncAliases;
    /** @var int revision claimed by validateSave() */
    private $rev = 0;

    public function __construct($oc, string $route, string $sep, callable $actionLink, callable $plainLink, string $adminLanguageKey, callable $syncAliases) {
        $this->oc = $oc;
        $this->route = $route;
        $this->sep = $sep;
        $this->actionLink = $actionLink;
        $this->plainLink = $plainLink;
        $this->adminLanguageKey = $adminLanguageKey;
        $this->syncAliases = $syncAliases;
    }

    private function link(string $method): string {
        return (string)call_user_func($this->actionLink, $method);
    }

    /** Everything the settings page shows, the same on OpenCart 3 and 4 (the controller adds its form links, header and footer). */
    public function viewData(): array {
        $data = [];

        if (method_exists($this->oc->language, 'all')) {
            foreach ($this->oc->language->all() as $key => $value) {
                $data[$key] = $value;
            }
        }

        $token = $this->oc->session->data['user_token'];

        $data['breadcrumbs'] = [
            ['text' => $this->oc->language->get('text_home'), 'href' => ($this->plainLink)('common/dashboard', 'user_token=' . $token)],
            ['text' => $this->oc->language->get('text_extension'), 'href' => ($this->plainLink)('marketplace/extension', 'user_token=' . $token . '&type=module')],
            ['text' => $this->oc->language->get('heading_title'), 'href' => ($this->plainLink)($this->route, 'user_token=' . $token)],
        ];

        $data['export_url'] = $this->link('export');
        $data['columns_url'] = $this->link('columns');
        $data['revoke_url'] = $this->link('revoke');
        $data['rename_url'] = $this->link('rename');
        $data['check_url'] = $this->link('check');
        $data['wipe_preview_url'] = $this->link('wipePreview');
        $data['wipe_url'] = $this->link('wipe');
        $data['clear_trash_url'] = $this->link('clearTrash');
        foreach (['css', 'js'] as $f) { // styles and script of this page: separate files, cached by the browser until the module changes
            $file = self::assetFile($f, $this->sep);
            $data['asset_' . $f . '_url'] = $this->link('asset') . '&f=' . $f . '&v=' . substr(md5((string)@filemtime($file) . Version::full()), 0, 8);
        }
        $data['trash'] = ImageStore::trashStats(defined('DIR_IMAGE') ? DIR_IMAGE : '');
        $data['trash']['mb'] = round($data['trash']['bytes'] / 1048576, 1);
        $data['remove_url'] = $this->link('remove');

        $fields = [
            'module_mcp_server_status'      => 0,
            'module_mcp_server_mode'        => 'read_only',
            'module_mcp_server_url_secret'  => '',
            'module_mcp_server_path'        => self::LEGACY_PATH,
            'module_mcp_server_conn_unlimited' => 0,
            'module_mcp_server_allowed_ips' => '',
            'module_mcp_server_origins'     => '',
            'module_mcp_server_log'         => 1,
            'module_mcp_server_db_names'    => 0,
            'module_mcp_server_oauth'       => 1,
            'module_mcp_server_rate_limit'  => 300,
            'module_mcp_server_delete_delay' => 0,
            'module_mcp_server_mail_hourly' => 60,
            'module_mcp_server_pii_hourly'  => 2000,
            'module_mcp_server_rev'         => 0,
        ];

        foreach ($fields as $key => $default) {
            if (isset($this->oc->request->post[$key])) {
                $data[$key] = $this->oc->request->post[$key]; // a refused OpenCart 3 form shows what was typed
            } else {
                $value = $this->oc->config->get($key);
                $data[$key] = ($value === null || $value === '') ? $default : $value;
            }
        }

        $data['module_mcp_server_mail_hourly'] = (int)(round(max(0, (int)$data['module_mcp_server_mail_hourly']) / 60) * 60); // the field steps by 60: an old 200 would block the form

        $https = !empty($this->oc->request->server['HTTPS']) && $this->oc->request->server['HTTPS'] !== 'off';
        $catalog = rtrim($https ? HTTPS_CATALOG : HTTP_CATALOG, '/');
        $pretty = (bool)$this->oc->config->get('module_mcp_server_pretty_urls') && (bool)$this->oc->config->get('config_seo_url');

        $data['token_set'] = $this->tokenIsSet();
        $data['seo_enabled'] = (bool)$this->oc->config->get('config_seo_url');
        $data['pretty_urls'] = $pretty;
        $data['endpoint_url'] = $catalog . '/index.php?route=' . $this->route . $this->sep . 'endpoint';
        $data['mcp_base'] = $catalog . '/';
        $data['mcp_url'] = $pretty ? $catalog . '/' . $this->path() : $data['endpoint_url'];
        $data['secret_base'] = $pretty ? $catalog . '/' : $data['endpoint_url'] . '&k='; // the secret URL's Copy button puts the address in front of the secret
        $data['endpoint_secret_url'] = $data['module_mcp_server_url_secret'] ? $catalog . '/' . $data['module_mcp_server_url_secret'] : '';
        $data['endpoint_secret_query_url'] = $data['module_mcp_server_url_secret'] ? $data['endpoint_url'] . '&k=' . $data['module_mcp_server_url_secret'] : '';
        $data['oauth_metadata_url'] = $pretty ? $catalog . '/.well-known/oauth-authorization-server' : $catalog . '/index.php?route=' . $this->route . $this->sep . 'oauth_metadata';
        $data['oauth_resource_url'] = $pretty ? $catalog . '/.well-known/oauth-protected-resource' : $catalog . '/index.php?route=' . $this->route . $this->sep . 'oauth_resource';
        $data['https'] = strpos($catalog, 'https://') === 0;

        // what goes into the assistant's "add connector" form: Claude takes the name and address from the link, ChatGPT and Perplexity have no such link (copy buttons instead)
        $connectorName = trim((string)$this->oc->config->get('config_name'));
        $data['connector_name'] = $connectorName !== '' ? $connectorName : 'OpenCart';
        $data['connect_clients'] = [
            ['id' => 'claude', 'name' => 'Claude', 'prefilled' => true, 'url' => 'https://claude.ai/customize/connectors?modal=add-custom-connector&connectorName=' . rawurlencode($data['connector_name']) . '&connectorUrl=' . rawurlencode($data['mcp_url']), 'steps' => $this->oc->language->get('text_client_claude_steps')],
            ['id' => 'chatgpt', 'name' => 'ChatGPT', 'prefilled' => false, 'url' => 'https://chatgpt.com/plugins', 'steps' => $this->oc->language->get('text_client_chatgpt_steps')],
            ['id' => 'perplexity', 'name' => 'Perplexity', 'prefilled' => false, 'url' => 'https://www.perplexity.ai/computer/connectors', 'steps' => $this->oc->language->get('text_client_perplexity_steps')],
        ];
        $data['claude_code_cmd'] = 'claude mcp add --transport http opencart "' . $data['mcp_url'] . '"';
        $data['inspector_cmd'] = 'npx -y @modelcontextprotocol/inspector --cli "' . $data['mcp_url'] . '" --transport http --method tools/list';

        // ---- access by admin sections (tables grouped like the OpenCart menu, field labels from the admin language files)
        // FREE build: the Access tab is locked on the Safe (read-only) profile - show it that way whatever is stored.
        $aclForView = \OcMcp\Version::FREE ? \OcMcp\TablePolicy::recommended('read') : $this->oc->config->get('module_mcp_server_acl');
        $policy = new \OcMcp\TablePolicy(DB_PREFIX, $aclForView);
        $dbw = new \OcMcp\Db($this->oc->db, DB_PREFIX);
        $language = $this->oc->language;
        $langDir = (string)$this->oc->config->get($this->adminLanguageKey);
        if (is_dir(DIR_LANGUAGE . $this->moduleLanguageCode())) {
            $langDir = $this->moduleLanguageCode(); // field labels follow the language picked inside the module
        }
        $sections = \OcMcp\AccessMap::build($policy, \OcMcp\AccessMap::loadTables($dbw), \OcMcp\AccessMap::loadColumns($dbw), DIR_LANGUAGE, $langDir !== '' ? $langDir : 'en-gb', function ($key) use ($language) {
            $value = $language->get($key);

            return $value === $key ? '' : $value;
        }, \OcMcp\SettingsPolicy::readableKeys($dbw, 0), DIR_TEMPLATE);

        // labels still in English (no Ukrainian admin pack) get the module's translation, Ukrainian ones stay; in English, names built from a column ("Gdpr ID") are made readable
        $sections = \OcMcp\FormLabels::translate($sections, strpos($this->moduleLanguageCode(), 'uk') === 0 ? 'uk' : 'en');

        (new \OcMcp\ChangeJournal($dbw))->runDue();
        $data['changes_url'] = $this->link('changes');
        $data['change_details_url'] = $this->link('changeDetails');
        $data['rollback_url'] = $this->link('rollback');
        $data['rollback_all_url'] = $this->link('rollback_all');
        $data['cancel_change_url'] = $this->link('cancelChange');
        $this->persistRequired($sections); // what the forms require: the server keeps it open at Full
        $data['access_json'] = json_encode(array('sections' => $sections, 'levels' => \OcMcp\TablePolicy::LEVELS, 'db_names' => (bool)$this->oc->config->get('module_mcp_server_db_names')), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $data['presets_json'] = json_encode(\OcMcp\TablePolicy::presets());
        $custom = $this->oc->config->get('module_mcp_server_acl_custom');
        $data['acl_custom_json'] = is_array($custom) && $custom ? json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null';

        // ---- OAuth connections
        $data['connections'] = [];

        if ($this->tableExists('mcp_oauth_token')) {
            $oauth = new \OcMcp\OAuthServer(new \OcMcp\Db($this->oc->db, DB_PREFIX), [], '');

            $labels = $this->connLabels();

            foreach ($oauth->listTokens() as $t) {
                $expired = strtotime($t['refresh_expires']) < time();
                $conn = 'oauth:' . $t['client_id'] . ':' . (int)$t['user_id'];
                $data['connections'][] = [
                    'token_id'    => (int)$t['token_id'],
                    'conn'        => $conn,
                    'label'       => isset($labels[$conn]) ? $labels[$conn] : '',
                    'client_name' => $t['client_name'],
                    'client_id'   => mb_strlen($t['client_id']) > 48 ? mb_substr($t['client_id'], 0, 48) . '…' : $t['client_id'],
                    'username'    => $t['username'],
                    'date_added'  => $t['date_added'],
                    'last_used'   => $t['last_used'],
                    'expires'     => $t['refresh_expires'],
                    'unlimited'   => $t['refresh_expires'] >= \OcMcp\OAuthServer::FOREVER,
                    'ip'          => $t['ip'],
                    'status'      => $t['revoked'] ? 'revoked' : ($expired ? 'expired' : 'active'),
                ];
            }
        }

        usort($data['connections'], function ($a, $b) {
            return ($a['status'] === 'active' ? 0 : 1) - ($b['status'] === 'active' ? 0 : 1) ?: $b['token_id'] - $a['token_id'];
        });
        $data['active_connections'] = 0;
        $data['inactive_connections'] = 0;
        foreach ($data['connections'] as $c) {
            if ($c['status'] === 'active') {
                $data['active_connections']++;
            } else {
                $data['inactive_connections']++;
            }
        }
        $data['module_lang'] = $this->moduleLanguageCode();
        // ---- license (checked against the store's configured catalog domain)
        $licenseCheck = (new \OcMcp\License())->check((string)$this->oc->config->get('module_mcp_server_license_key'), \OcMcp\License::storeHost(HTTP_CATALOG));
        $data['licensed'] = $licenseCheck['valid'];
        // FREE / demo build: read-only, premium tabs disabled. Activated with the universal key 'ai-admin-free'
        // (the owner enters it; it is valid on any domain). Until then the activation card shows.
        $data['free'] = \OcMcp\Version::FREE;
        $data['support_tg'] = 'https://t.me/ocaiadmin';
        $data['license_domain'] = \OcMcp\License::storeHost(HTTP_CATALOG);
        $data['license_reason'] = $licenseCheck['valid'] ? '' : $this->oc->language->get('ui_license_reason_' . $licenseCheck['reason']);
        $data['license_info'] = $licenseCheck['payload'] ? [
            'licensee' => (string)($licenseCheck['payload']['licensee'] ?? ''),
            'domains'  => implode(', ', (array)($licenseCheck['payload']['domains'] ?? [])), // just the domains: a key for shop.com also works on its subdomains, not spelled out
            'expires'  => (string)($licenseCheck['payload']['expires'] ?? ''),
        ] : null;
        $data['license_url'] = $this->link('license');
        $data['crypto_ok'] = function_exists('openssl_verify'); // openssl checks the OCMCP2 keys; every OpenCart host has it

        $config = $this->oc->config;
        $data = array_merge($data, \OcMcp\AdminContent::data(new \OcMcp\Db($this->oc->db, DB_PREFIX), function (string $k) use ($config) { return $config->get($k); }, $this->moduleLanguageCode(), DIR_SYSTEM));
        $data['content_url'] = $this->link('content');
        $data['prompts_url'] = $this->link('prompts');
        $stats = (new \OcMcp\Stats(new \OcMcp\Db($this->oc->db, DB_PREFIX)))->overview();
        $data['stats_json'] = json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $data['ai_connected'] = $data['active_connections'] > 0; // green only with a live connection on the Connections tab, the same count as "AI assistants connected"

        // ---- log (rows are loaded by AJAX)
        $data['logs_url'] = $this->link('logs');
        $data['archive_url'] = $this->link('archive');
        $data['clear_log_url'] = $this->link('clearLog');
        $data['archives_json'] = json_encode($this->archives(), JSON_UNESCAPED_UNICODE);
        $data['log_total'] = $this->tableExists('mcp_log') ? (int)$this->oc->db->query("SELECT COUNT(*) AS c FROM `" . DB_PREFIX . "mcp_log`")->row['c'] : 0; // recent (live) calls; archived ones stay in the export
        $data['logs'] = array_fill(0, min(1, $data['log_total']), 1);

        $this->mirror(); // the copy of the settings that survives OpenCart's own uninstall

        return $data;
    }

    /** Errors of a settings save: [field => text] with field warning / token / url_secret (empty: the form may be saved). */
    public function validateSave(): array {
        $json = [];

        if ($this->oc->request->server['REQUEST_METHOD'] != 'POST' || !$this->oc->user->hasPermission('modify', $this->route)) {
            $json['error']['warning'] = $this->oc->language->get('error_permission');
        }

        $token = trim((string)($this->oc->request->post['module_mcp_server_token'] ?? ''));
        $hasToken = $token !== '' || ($this->tokenIsSet() && empty($this->oc->request->post['module_mcp_server_token_clear']));
        $secret = trim((string)($this->oc->request->post['module_mcp_server_url_secret'] ?? ''));
        $oauth = !empty($this->oc->request->post['module_mcp_server_oauth']);

        if ($token !== '' && strlen($token) < 16) {
            $json['error']['token'] = $this->oc->language->get('error_token');
        }

        if ($secret !== '' && (strlen($secret) < 16 || !preg_match('/^[A-Za-z0-9_-]+$/', $secret))) {
            $json['error']['url_secret'] = $this->oc->language->get('error_url_secret');
        }

        if (isset($this->oc->request->post['module_mcp_server_path']) && !$this->pathFree(trim((string)$this->oc->request->post['module_mcp_server_path']), $secret)) {
            $json['error']['path'] = $this->oc->language->get('error_path');
        }

        if (!empty($this->oc->request->post['module_mcp_server_status']) && !$hasToken && $secret === '' && !$oauth) {
            $json['error']['warning'] = $this->oc->language->get('error_credentials');
        }

        $aclJson = (string)($this->oc->request->post['module_mcp_server_acl_json'] ?? '');

        if (!$json && $aclJson !== '' && $this->decodeAcl($aclJson) === [] && trim(html_entity_decode($aclJson, ENT_QUOTES, 'UTF-8')) !== '{}') {
            $json['error']['warning'] = $this->oc->language->get('error_acl');
        }

        if (!$json) {
            $rev = $this->claimRevision($this->oc->request->post['module_mcp_server_rev'] ?? 0);

            if (!$rev) {
                $json['error']['warning'] = $this->oc->language->get('error_stale');
            } else {
                $this->rev = $rev;
            }
        }

        return $json['error'] ?? [];
    }

    /** Write the settings of a validated save; returns the new revision. */
    public function writeSettings(): int {
        $json = [];
        $rev = $this->rev;
        $secret = trim((string)($this->oc->request->post['module_mcp_server_url_secret'] ?? ''));

        if (!$json) {
            $post = $this->oc->request->post;
            $post['module_mcp_server_rev'] = $rev;
            $required = $this->requiredNow();
            $post['module_mcp_server_required'] = json_encode($required);
            $post['module_mcp_server_conn_names'] = (string)$this->oc->config->get('module_mcp_server_conn_names'); // the owner's names for connections live outside the form
            $post['module_mcp_server_acl'] = $this->decodeAcl((string)($post['module_mcp_server_acl_json'] ?? ''), $required);
            unset($post['module_mcp_server_acl_json']);
            $custom = $this->decodeAcl((string)($post['module_mcp_server_acl_custom_json'] ?? ''), $required);
            $post['module_mcp_server_acl_custom'] = $custom ? $custom : ''; // "My settings": the owner's own access to come back to after trying a preset
            unset($post['module_mcp_server_acl_custom_json']);

            $key = (string)$this->oc->config->get('module_mcp_server_oauth_key');
            $post['module_mcp_server_oauth_key'] = $key !== '' ? $key : \OcMcp\OAuthServer::randomToken(32);
            $path = isset($post['module_mcp_server_path']) ? trim((string)$post['module_mcp_server_path']) : $this->path();
            $post['module_mcp_server_path'] = $path;
            $post['module_mcp_server_pretty_urls'] = call_user_func($this->syncAliases, $secret, $path) ? 1 : 0;
            $post['module_mcp_server_conn_unlimited'] = empty($post['module_mcp_server_conn_unlimited']) ? 0 : 1;
            $post['module_mcp_server_log'] = 1; // the request log is always kept: no switch in the settings
            $oldSecret = trim((string)$this->oc->config->get('module_mcp_server_url_secret'));
            $this->connectionTerms($path !== $this->path() || ($oldSecret !== '' && $secret !== $oldSecret), (bool)$post['module_mcp_server_conn_unlimited']);
            $post = $this->hashToken($post);
            $post['module_mcp_server_rate_limit'] = isset($post['module_mcp_server_rate_limit']) ? max(0, (int)$post['module_mcp_server_rate_limit']) : 300;
            $post['module_mcp_server_delete_delay'] = 0; // deletions are immediate: the journal restores them
            $post['module_mcp_server_mail_hourly'] = isset($post['module_mcp_server_mail_hourly']) ? (int)(round(max(0, (int)$post['module_mcp_server_mail_hourly']) / 60) * 60) : 60; // whole e-mails per minute
            $post['module_mcp_server_pii_hourly'] = 2000; // protection against copying the customer base: fixed, not a setting
            $post['module_mcp_server_origins'] = '';

            $this->oc->load->model('setting/setting');

            // AI behaviour, styling and images live under their own setting code
            if (isset($post['module_mcp_server_content_json'])) {
                $this->oc->model_setting_setting->editSetting('module_mcp_server_content', array('module_mcp_server_content' => \OcMcp\AdminContent::content((string)$post['module_mcp_server_content_json'])));
            }

            unset($post['module_mcp_server_content_json'], $post['module_mcp_server_fields_json'], $post['module_mcp_server_page_types_json']); // fields_json, page_types_json: an older form
            $post['module_mcp_server_mode'] = \OcMcp\TablePolicy::deriveMode(is_array($post['module_mcp_server_acl']) ? $post['module_mcp_server_acl'] : array());

            $this->oc->model_setting_setting->editSetting('module_mcp_server', $post);

        }

        $this->mirror();

        return $rev;
    }

    /** AJAX: columns of a table + current writable list. */
    public function columns(): array {
        $json = [];

        if (!$this->oc->user->hasPermission('access', $this->route)) {
            $json['error'] = 'permission';
        } else {
            $table = preg_replace('/[^A-Za-z0-9_]/', '', (string)($this->oc->request->get['table'] ?? ''));
            $short = (DB_PREFIX !== '' && strpos($table, DB_PREFIX) === 0) ? substr($table, strlen(DB_PREFIX)) : $table;

            if ($table === '' || !$this->tableExists($short)) {
                $json['error'] = 'not_found';
            } else {
                $full = DB_PREFIX . $short;
                $policy = new \OcMcp\TablePolicy(DB_PREFIX, $this->oc->config->get('module_mcp_server_acl'));
                $hidden = $policy->hiddenColumns($short);
                $json['table'] = $full;
                $json['columns'] = [];

                $query = $this->oc->db->query("SHOW COLUMNS FROM `" . $full . "`");

                foreach ($query->rows as $col) {
                    $json['columns'][] = ['name' => $col['Field'], 'type' => $col['Type'], 'key' => $col['Key'], 'hidden' => in_array(strtolower($col['Field']), $hidden, true)];
                }

                $json['writable'] = $policy->writableColumns($short);
            }
        }

        return $json;
    }

    public function revoke(): array {

        $json = [];

        if ($this->oc->request->server['REQUEST_METHOD'] != 'POST' || !$this->oc->user->hasPermission('modify', $this->route)) {
            $json['error'] = $this->oc->language->get('error_permission');
        } elseif ($this->tableExists('mcp_oauth_token')) {
            $oauth = new \OcMcp\OAuthServer(new \OcMcp\Db($this->oc->db, DB_PREFIX), [], '');

            if (isset($this->oc->request->get['token_id'])) {
                $oauth->revokeToken((int)$this->oc->request->get['token_id']);
            } elseif (isset($this->oc->request->get['all'])) {
                $oauth->revokeAll();
            }

            $json['success'] = $this->oc->language->get('text_revoked');
        }

        return $json;
    }

    /** AJAX: does the short address really reach this server? Asked from the server side: the owner sees the hosting's own answer (404 = .htaccess rewrites off). */
    public function check(): array {
        $json = [];

        if (!$this->oc->user->hasPermission('access', $this->route)) {
            $json['error'] = $this->oc->language->get('error_permission');
        } else {
            $catalog = rtrim((defined('HTTPS_CATALOG') && strpos(HTTPS_CATALOG, 'https://') === 0) ? HTTPS_CATALOG : HTTP_CATALOG, '/');
            $url = $catalog . '/' . $this->path();
            $r = \OcMcp\AliasCheck::probe($url);
            $v = \OcMcp\AliasCheck::verdict($r);
            $json['verdict'] = $v;
            $json['level'] = $v === 'ok' ? 'ok' : ($v === 'unreachable' ? 'warn' : 'bad');
            $json['text'] = sprintf($this->oc->language->get('ui_check_short_' . $v), $url, (string)$r['status']);
        }

        return $json;
    }

    /** AJAX: batches of changes made by the AI. */
    public function changes(): array {
        $json = $this->oc->user->hasPermission('access', $this->route)
            ? $this->changesPage()
            : array('error' => 'permission');

        return $json;
    }

    /** AJAX (POST): roll back every open change that matches the History filter (connection, period), newest first. */
    public function rollbackAll(): array {

        if ($this->oc->request->server['REQUEST_METHOD'] != 'POST' || !$this->oc->user->hasPermission('modify', $this->route)) {
            $json = array('error' => $this->oc->language->get('error_permission'));
        } else {
            $db = new \OcMcp\Db($this->oc->db, DB_PREFIX);
            $filter = $this->changesFilter($this->oc->request->post);
            $json = (new \OcMcp\ChangeJournal($db, 'admin:' . $this->oc->user->getUserName(), '_rollback'))->rollbackMany($filter, !empty($this->oc->request->post['force']));
            (new \OcMcp\Logger($db))->log((string)$this->oc->request->server['REMOTE_ADDR'], 'admin:' . $this->oc->user->getUserName(), '_rollback', $filter, $json['conflicts'] ? 'error' : 'ok', $json['rolled_back'] . ' rolled back in ' . $json['batches'] . ' batch(es), ' . $json['conflicts'] . ' conflict(s)');
            $json['success'] = sprintf($this->oc->language->get('ui_rollback_all_done'), $json['rolled_back'], $json['batches'], $json['conflicts']) . $this->blockedNote($json);
        }

        return $json;
    }

    /** AJAX: before/after rows of one batch. */
    public function changeDetails(): array {
        $batch = isset($this->oc->request->get['batch']) ? preg_replace('/[^a-f0-9]/', '', (string)$this->oc->request->get['batch']) : '';
        $journal = new \OcMcp\ChangeJournal(new \OcMcp\Db($this->oc->db, DB_PREFIX));
        $json = $this->oc->user->hasPermission('access', $this->route) // the first 500 rows: a 30000-order change would not fit the browser
            ? array('rows' => $journal->detailsForDisplay($batch, new \OcMcp\TablePolicy(DB_PREFIX, $this->oc->config->get('module_mcp_server_acl'))), 'total' => $journal->detailCount($batch))
            : array('error' => 'permission');

        return $json;
    }

    /** " Not undone: N, a newer AI change of the same fields (#88) has to go first." after a rollback, '' when nothing was held back that way. */
    private function blockedNote(array $json): string {
        return empty($json['blocked']) ? '' : ' ' . sprintf($this->oc->language->get('text_rb_blocked'), $json['blocked'], '#' . implode(', #', $json['blocked_by']));
    }

    /** AJAX (POST): roll a batch back. */
    public function rollback(): array {
        $json = array();

        if ($this->oc->request->server['REQUEST_METHOD'] != 'POST' || !$this->oc->user->hasPermission('modify', $this->route)) {
            $json['error'] = $this->oc->language->get('error_permission');
        } else {
            $db = new \OcMcp\Db($this->oc->db, DB_PREFIX);
            $batch = isset($this->oc->request->post['batch']) ? preg_replace('/[^a-f0-9]/', '', (string)$this->oc->request->post['batch']) : '';
            $journal = new \OcMcp\ChangeJournal($db, 'admin:' . $this->oc->user->getUserName(), '_rollback');
            $json = $journal->rollback($batch, !empty($this->oc->request->post['force']));
            (new \OcMcp\Logger($db))->log((string)$this->oc->request->server['REMOTE_ADDR'], 'admin:' . $this->oc->user->getUserName(), '_rollback', array('change_no' => $journal->numberOf($batch), 'batch' => $batch, 'force' => !empty($this->oc->request->post['force'])), $json['conflicts'] ? 'error' : 'ok', $json['rolled_back'] . ' rolled back, ' . $json['conflicts'] . ' conflict(s), ' . $json['cancelled'] . ' cancelled');
            $json['success'] = sprintf($this->oc->language->get('text_rolled_back'), $json['rolled_back'], $json['conflicts'], $json['cancelled']) . $this->blockedNote($json);
        }

        return $json;
    }

    /** AJAX (POST): cancel pending (delayed) deletions of a batch. */
    public function cancelChange(): array {
        $json = array();

        if ($this->oc->request->server['REQUEST_METHOD'] != 'POST' || !$this->oc->user->hasPermission('modify', $this->route)) {
            $json['error'] = $this->oc->language->get('error_permission');
        } else {
            $db = new \OcMcp\Db($this->oc->db, DB_PREFIX);
            $batch = isset($this->oc->request->post['batch']) ? preg_replace('/[^a-f0-9]/', '', (string)$this->oc->request->post['batch']) : '';
            $journal = new \OcMcp\ChangeJournal($db, 'admin:' . $this->oc->user->getUserName(), '_cancel_delete');
            $n = $journal->cancel($batch);
            (new \OcMcp\Logger($db))->log((string)$this->oc->request->server['REMOTE_ADDR'], 'admin:' . $this->oc->user->getUserName(), '_cancel_delete', array('change_no' => $journal->numberOf($batch), 'batch' => $batch), 'ok', $n . ' cancelled');
            $json['success'] = sprintf($this->oc->language->get('text_cancelled_n'), $n);
        }

        return $json;
    }

    /** AJAX: filtered page of the call log. */
    public function logs(): array {
        $json = array();

        if (!$this->oc->user->hasPermission('access', $this->route)) {
            $json['error'] = 'permission';
        } else {
            $g = $this->oc->request->get;
            $filters = array();

            foreach (array('date_from', 'date_to', 'range', 'tool', 'client', 'status', 'q') as $k) {
                $filters[$k] = isset($g[$k]) ? trim(html_entity_decode((string)$g[$k], ENT_QUOTES, 'UTF-8')) : '';
            }

            $json = (new \OcMcp\Logger(new \OcMcp\Db($this->oc->db, DB_PREFIX)))->page($filters, isset($g['offset']) ? (int)$g['offset'] : 0, self::pageSize($g));
            $labels = $this->connLabels();

            foreach ($json['rows'] as $i => $r) {
                $json['rows'][$i]['conn_name'] = isset($r['caller'], $labels[$r['caller']]) ? $labels[$r['caller']] : '';
            }
        }

        return $json;
    }

    /** The archives of the call log for the History tab (empty until the first one is made). */
    private function archives(): array {
        try {
            return $this->tableExists(\OcMcp\Logger::SETS) ? (new \OcMcp\Logger(new \OcMcp\Db($this->oc->db, DB_PREFIX)))->archives() : array();
        } catch (\Throwable $e) {
            return array();
        }
    }

    /** The admin who made an archive, as the History tab shows it. */
    private function userName(): string {
        return method_exists($this->oc->user, 'getUserName') ? (string)$this->oc->user->getUserName() : '';
    }

    /** AJAX (POST): move records older than N days to the archive table. Nothing is deleted. */
    public function archive(): array {
        $json = array();

        if ($this->oc->request->server['REQUEST_METHOD'] != 'POST' || !$this->oc->user->hasPermission('modify', $this->route)) {
            $json['error'] = $this->oc->language->get('error_permission');
        } else {
            $days = isset($this->oc->request->post['days']) ? (int)$this->oc->request->post['days'] : 90;
            $moved = (new \OcMcp\Logger(new \OcMcp\Db($this->oc->db, DB_PREFIX)))->archive($days, $this->userName());
            $json['success'] = sprintf($this->oc->language->get('text_archived'), $moved);
            $json['moved'] = $moved;
            $json['archives'] = $this->archives();
        }

        return $json;
    }

    /** AJAX (POST): clear the log view by moving every entry to the archive. Nothing is deleted; the chain stays verifiable. */
    public function clearLog(): array {
        $json = array();

        if ($this->oc->request->server['REQUEST_METHOD'] != 'POST' || !$this->oc->user->hasPermission('modify', $this->route)) {
            $json['error'] = $this->oc->language->get('error_permission');
        } else {
            $moved = (new \OcMcp\Logger(new \OcMcp\Db($this->oc->db, DB_PREFIX)))->clear($this->userName()); // move every entry to the archive: the view empties, nothing is lost
            $json['success'] = sprintf($this->oc->language->get('ui_log_cleared'), $moved);
            $json['moved'] = $moved;
            $json['archives'] = $this->archives();
        }

        return $json;
    }

    /** AJAX: check and save a license key (stored under its own setting code, so saving the module form never drops it). */
    /** AJAX (POST): the owner's content rules (own setting code, so saving the module form never drops them). */
    public function content(): array {
        $json = [];

        if ($this->oc->request->server['REQUEST_METHOD'] != 'POST' || !$this->oc->user->hasPermission('modify', $this->route)) {
            $json['error'] = $this->oc->language->get('error_permission');
        } else {
            $settings = \OcMcp\AdminContent::content((string)(isset($this->oc->request->post['content']) ? $this->oc->request->post['content'] : ''));
            $this->oc->load->model('setting/setting');
            $this->oc->model_setting_setting->editSetting('module_mcp_server_content', ['module_mcp_server_content' => $settings]);
            $json = ['success' => $this->oc->language->get('ui_content_saved'), 'content' => $settings];
        }

        return $json;
    }

    /** AJAX (POST): save / add / reset one of the owner's commands. */
    public function prompts(): array {

        if ($this->oc->request->server['REQUEST_METHOD'] != 'POST' || !$this->oc->user->hasPermission('modify', $this->route)) {
            $json = ['error' => $this->oc->language->get('error_permission')];
        } else {
            $config = $this->oc->config;
            $post = $this->oc->request->post;

            foreach (['title', 'description', 'body', 'section'] as $k) {
                $post[$k] = html_entity_decode((string)($post[$k] ?? ''), ENT_QUOTES, 'UTF-8'); // OpenCart escapes request data
            }

            $json = \OcMcp\AdminContent::prompts(new \OcMcp\Db($this->oc->db, DB_PREFIX), $this->moduleLanguageCode(), $post);

            if (!empty($json['ok'])) {
                $json['success'] = $this->oc->language->get('ui_prompt_saved');
            }
        }

        return $json;
    }

    public function license(): array {
        $json = [];

        if ($this->oc->request->server['REQUEST_METHOD'] != 'POST' || !$this->oc->user->hasPermission('modify', $this->route)) {
            $json['error'] = $this->oc->language->get('error_permission');
        } else {
            $key = trim((string)(isset($this->oc->request->post['key']) ? $this->oc->request->post['key'] : ''));
            $check = (new \OcMcp\License())->check($key, \OcMcp\License::storeHost(HTTP_CATALOG));

            if ($check['valid']) {
                $this->oc->load->model('setting/setting');
                $this->oc->model_setting_setting->editSetting('module_mcp_server_license', ['module_mcp_server_license_key' => $key]);
                $json['success'] = $this->oc->language->get('ui_license_ok');
            } else {
                $json['error'] = sprintf($this->oc->language->get('ui_license_reason_' . $check['reason']), \OcMcp\License::storeHost(HTTP_CATALOG));
            }
        }

        return $json;
    }

    /** AJAX (POST): name a connection ("Anna" instead of "Claude · admin"); an empty name takes the app's own name back. */
    public function rename(): array {
        $json = array();

        if ($this->oc->request->server['REQUEST_METHOD'] != 'POST' || !$this->oc->user->hasPermission('modify', $this->route)) {
            $json['error'] = $this->oc->language->get('error_permission');
        } else {
            $conn = isset($this->oc->request->post['conn']) ? (string)$this->oc->request->post['conn'] : '';
            $name = isset($this->oc->request->post['name']) ? trim(strip_tags(html_entity_decode((string)$this->oc->request->post['name'], ENT_QUOTES, 'UTF-8'))) : '';
            $name = mb_substr(preg_replace('/\s+/u', ' ', $name), 0, 64);

            if (!preg_match('/^oauth:.+:\d+$/', $conn)) {
                $json['error'] = $this->oc->language->get('error_permission');
            } else {
                $labels = $this->connLabels();

                if ($name === '') {
                    unset($labels[$conn]);
                } else {
                    $labels[$conn] = $name;
                }

                $this->saveSetting('module_mcp_server_conn_names', json_encode($labels, JSON_UNESCAPED_UNICODE));
                $json = array('success' => $this->oc->language->get('ui_conn_renamed'), 'name' => $name);
            }
        }

        return $json;
    }

    /** Batches of the History tab: state (open / done / all), connection and period; who did it in the owner's words; counts for the chips. */
    public function changesPage(): array {
        $journal = new \OcMcp\ChangeJournal(new \OcMcp\Db($this->oc->db, DB_PREFIX));
        $journal->prune(); // a new working day drops the oldest of the thirty at once, not only at the hourly clean-up
        $filter = $this->changesFilter($this->oc->request->get);
        $page = $journal->batches(isset($this->oc->request->get['offset']) ? (int)$this->oc->request->get['offset'] : 0, self::pageSize($this->oc->request->get, array(10, 25, 50), 10), $filter);
        $names = $this->connectionNames();

        foreach ($page['rows'] as $i => $r) {
            $page['rows'][$i]['conn'] = $r['caller'] . '|' . $r['client'];
            $page['rows'][$i]['conn_name'] = $this->connectionName((string)$r['caller'], (string)$r['client'], $names);
        }

        $page['counts'] = $journal->counts($filter);
        $page['connections'] = array();

        foreach ($journal->connections() as $c) {
            $page['connections'][] = array('conn' => $c['caller'] . '|' . $c['client'], 'name' => $this->connectionName((string)$c['caller'], (string)$c['client'], $names), 'batches' => (int)$c['batches'], 'last' => $c['last']);
        }

        return $page;
    }

    /** OAuth connections by "client_id:user_id": the app and the user who authorised it, as the Connections tab names them. */
    public function connectionNames(): array {
        $names = array();

        if ($this->tableExists('mcp_oauth_token')) {
            foreach ((new \OcMcp\OAuthServer(new \OcMcp\Db($this->oc->db, DB_PREFIX), [], ''))->listTokens() as $t) {
                $k = $t['client_id'] . ':' . (int)$t['user_id'];

                if (!isset($names[$k])) {
                    $names[$k] = trim($t['client_name'] . ((string)$t['username'] !== '' ? ' · ' . $t['username'] : ''));
                }
            }
        }

        foreach ($this->connLabels() as $conn => $label) { // the owner's own names win
            if (strpos($conn, 'oauth:') === 0) {
                $names[substr($conn, 6)] = $label;
            }
        }

        return $names;
    }

    /** A connection in the owner's words: the OAuth app and its user; else the program's own name and the IP behind a token. */
    public function connectionName(string $caller, string $client, array $names): string {
        if (preg_match('/^oauth:(.+):(\d+)$/', $caller, $m)) {
            return isset($names[$m[1] . ':' . $m[2]]) ? $names[$m[1] . ':' . $m[2]] : trim('OAuth ' . $client);
        }

        if (strpos($caller, 'token:') === 0) {
            $ip = substr($caller, 6);

            return ($client !== '' ? $client : $this->oc->language->get('ui_conn_token')) . ($ip !== '' ? ' · ' . $ip : '');
        }

        return $client !== '' ? $client : $this->oc->language->get('ui_conn_unknown');
    }

    /** Rows per page of the History tables (changes: 10/25/50 items, request log: 25/50/100 rows). */
    public static function pageSize(array $in, array $sizes = array(25, 50, 100), $default = 50) {
        $n = isset($in['limit']) ? (int)$in['limit'] : (int)$default;

        return in_array($n, $sizes, true) ? $n : (int)$default;
    }

    public function changesFilter(array $in): array {
        $since = '';
        $period = isset($in['period']) ? (string)$in['period'] : '';

        if ($period === 'session') { // the AI's latest session: back from the newest change to the first pause over 20 minutes
            $since = (new \OcMcp\ChangeJournal(new \OcMcp\Db($this->oc->db, DB_PREFIX)))->sessionStart();
        } elseif ($period === 'today') {
            $since = date('Y-m-d') . ' 00:00:00';
        } elseif ($period === '24h') {
            $since = date('Y-m-d H:i:s', time() - 86400);
        } elseif ($period === '7d') {
            $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        } elseif ($period === '30d') {
            $since = date('Y-m-d H:i:s', time() - 30 * 86400);
        }

        $f = array('since' => $since, 'state' => isset($in['state']) && in_array($in['state'], array('open', 'done', 'all'), true) ? $in['state'] : 'all', 'no' => isset($in['no']) ? (int)preg_replace('/\D/', '', (string)$in['no']) : 0);
        $conn = isset($in['conn']) ? html_entity_decode((string)$in['conn'], ENT_QUOTES, 'UTF-8') : '';

        if ($conn !== '') { // "caller|client": the connection as History lists it, an empty part included
            $f['caller'] = (string)strstr($conn, '|', true);
            $f['client_eq'] = (string)substr((string)strstr($conn, '|'), 1);
        }

        return $f;
    }

    /**
     * Settings saved from two tabs (or by two admins): the form carries the revision it was opened with and the save moves it
     * on in one guarded statement. A stale form gets 0 and must reload instead of silently overwriting the newer settings.
     */
    public function claimRevision($posted): int {
        $db = new \OcMcp\Db($this->oc->db, DB_PREFIX);
        $posted = (int)$posted;
        $has = $db->row("SELECT 1 AS x FROM " . $db->t('setting') . " WHERE `store_id` = 0 AND `key` = 'module_mcp_server_rev'") !== null;

        if (!$has) {
            return $posted === 0 ? 1 : 0; // first save ever; a form with a revision while the row is missing means a save is running right now
        }

        return $db->exec("UPDATE " . $db->t('setting') . " SET `value` = `value` + 1 WHERE `store_id` = 0 AND `key` = 'module_mcp_server_rev' AND `value` = '" . $posted . "'") === 1 ? $posted + 1 : 0;
    }

    public function decodeAcl(string $raw, array $required = []): array {
        $decoded = json_decode(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'), true);
        $out = [];

        if (!is_array($decoded)) {
            return $out;
        }

        foreach ($decoded as $table => $rule) {
            $table = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
            $level = is_array($rule) && isset($rule['level']) ? (string)$rule['level'] : '';

            if ($table === '' || !in_array($level, \OcMcp\TablePolicy::LEVELS, true)) {
                continue;
            }

            $columns = [];

            if (is_array($rule) && isset($rule['columns']) && is_array($rule['columns'])) {
                foreach ($rule['columns'] as $c) {
                    $c = preg_replace('/[^A-Za-z0-9_]/', '', (string)$c);

                    if ($c !== '') {
                        $columns[] = $c;
                    }
                }
            }

            $hidden = [];

            if (is_array($rule) && isset($rule['hidden']) && is_array($rule['hidden'])) {
                foreach ($rule['hidden'] as $c) {
                    $c = preg_replace('/[^A-Za-z0-9_]/', '', (string)$c);

                    if ($c !== '') {
                        $hidden[] = $c;
                    }
                }
            }

            // saved exactly as the server will enforce it: lower case, no prefix, level clamped to the table's ceiling, a hidden field is not writable
            $table = strtolower($table);

            if (DB_PREFIX !== '' && strpos($table, strtolower(DB_PREFIX)) === 0) {
                $table = substr($table, strlen(DB_PREFIX));
            }

            $hidden = array_values(array_unique(array_map('strtolower', $hidden)));

            if ($level === \OcMcp\TablePolicy::FULL && isset($required[$table])) {
                $hidden = array_values(array_diff($hidden, $required[$table])); // the AI creates records here: the form's required fields stay open
            }

            $columns = array_values(array_diff(array_unique(array_map('strtolower', $columns)), $hidden));
            $out[$table] = ['level' => \OcMcp\TablePolicy::cap($table, $level), 'columns' => $columns, 'hidden' => $hidden];
        }

        return $out;
    }

    /** table => columns the store's own forms mark required, read from the Details sections (the star of the admin form). */
    public static function requiredFrom(array $sections): array {
        $map = array();

        foreach ($sections as $sec) {
            foreach ($sec['subs'] as $sub) {
                foreach (isset($sub['groups']) ? $sub['groups'] : array() as $g) {
                    foreach ($g['fields'] as $f) {
                        if (!empty($f['required']) && empty($f['technical'])) {
                            $map[$f['table']][] = $f['column'];
                        }
                    }
                }
            }
        }

        foreach ($map as $t => $cols) {
            $map[$t] = array_values(array_unique($cols));
        }

        ksort($map);

        return $map;
    }

    /** The required map of this store right now (its own templates), for the save and the server. */
    public function requiredNow(): array {
        $dbw = new \OcMcp\Db($this->oc->db, DB_PREFIX);
        $sections = \OcMcp\AccessMap::build(new \OcMcp\TablePolicy(DB_PREFIX, $this->oc->config->get('module_mcp_server_acl')), \OcMcp\AccessMap::loadTables($dbw), \OcMcp\AccessMap::loadColumns($dbw), DIR_LANGUAGE, 'en-gb', function ($key) { return ''; }, \OcMcp\SettingsPolicy::readableKeys($dbw, 0), DIR_TEMPLATE);

        return self::requiredFrom($sections);
    }

    /** Keep module_mcp_server_required in step with the forms (first open after an update, a changed template) without a save. */
    public function persistRequired(array $sections): void {
        $json = json_encode(self::requiredFrom($sections));

        if ($json === false || $json === (string)$this->oc->config->get('module_mcp_server_required')) {
            return;
        }

        $this->saveSetting('module_mcp_server_required', $json);
    }

    /** One module setting written on its own (the form's save is not involved). */
    public function saveSetting(string $key, string $value): void {
        $dbw = new \OcMcp\Db($this->oc->db, DB_PREFIX);
        $dbw->exec("DELETE FROM " . $dbw->t('setting') . " WHERE `store_id` = 0 AND `code` = 'module_mcp_server' AND `key` = " . $dbw->q($key));
        $dbw->exec("INSERT INTO " . $dbw->t('setting') . " SET `store_id` = 0, `code` = 'module_mcp_server', `key` = " . $dbw->q($key) . ", `value` = " . $dbw->q($value) . ", `serialized` = 0");
        $this->oc->config->set($key, $value);
        $this->mirror();
    }

    /** The owner's names for connections: "oauth:<client_id>:<user_id>" => name. */
    public function connLabels(): array {
        $raw = $this->oc->config->get('module_mcp_server_conn_names');
        $map = is_array($raw) ? $raw : json_decode((string)$raw, true);
        $out = array();

        foreach (is_array($map) ? $map : array() as $k => $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[(string)$k] = trim($v);
            }
        }

        return $out;
    }

    /** Replace the plain token in the posted settings by its sha256 hash (the token itself is never stored). */
    public function hashToken(array $post): array {
        $new = isset($post['module_mcp_server_token']) ? trim((string)$post['module_mcp_server_token']) : '';
        $existing = (string)$this->oc->config->get('module_mcp_server_token_hash');
        $legacy = (string)$this->oc->config->get('module_mcp_server_token');

        if (!empty($post['module_mcp_server_token_clear'])) {
            $post['module_mcp_server_token_hash'] = '';
        } elseif ($new !== '') {
            $post['module_mcp_server_token_hash'] = hash('sha256', $new);
        } elseif ($existing !== '') {
            $post['module_mcp_server_token_hash'] = $existing;
        } else {
            $post['module_mcp_server_token_hash'] = $legacy !== '' ? hash('sha256', $legacy) : '';
        }

        $post['module_mcp_server_token'] = '';
        unset($post['module_mcp_server_token_clear']);

        return $post;
    }

    /** The address of a store set up before the random one: https://shop/mcp. */
    const LEGACY_PATH = 'mcp';

    /** Notice for the owner after a save that revoked the connections (empty: nothing was revoked). */
    public $revokedNotice = '';

    /** The path of the store's MCP address (https://shop/<path>): random since this version, "mcp" for older stores. */
    public function path(): string {
        $path = trim((string)$this->oc->config->get('module_mcp_server_path'));

        return $path !== '' ? $path : self::LEGACY_PATH;
    }

    /** A new random path: it does not let anybody in (sign-in or the token still do), it only makes the address the store's own. */
    public static function newPath(): string {
        return 'mcp-' . substr(strtolower(bin2hex(random_bytes(8))), 0, 12);
    }

    /** A path the address may take: its own SEO keyword, not one of the module's other addresses, not a page of the store. */
    public function pathFree(string $path, string $secret): bool {
        if ($path === $this->path() && strcasecmp($path, $secret) !== 0) {
            return true; // the address the store has now (also the short "mcp" of older stores): nothing to check
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{3,63}$/', $path) || strcasecmp($path, $secret) === 0 || in_array(strtolower($path), ['mcp-oauth-authorize', 'mcp-oauth-token', 'mcp-oauth-register', '.well-known', 'oauth-protected-resource', 'oauth-authorization-server', 'openid-configuration'], true)) {
            return false;
        }

        $db = new Db($this->oc->db, DB_PREFIX);
        $route = $db->rows("SHOW COLUMNS FROM " . $db->t('seo_url') . " LIKE 'value'") ? '`value`' : '`query`'; // OpenCart 4: key/value, OpenCart 3: query

        return !$db->rows("SELECT 1 FROM " . $db->t('seo_url') . " WHERE `keyword` = " . $db->q($path) . " AND " . $route . " NOT LIKE '%mcp_server%' LIMIT 1");
    }

    /**
     * After a save: a new address or secret URL revokes every OAuth connection (the assistants connect again with the new one);
     * "no time limit" moves the open connections to no expiry, switching it off gives them the usual 30 days from now.
     */
    private function connectionTerms(bool $addressChanged, bool $unlimited): void {
        if (!$this->tableExists('mcp_oauth_token')) {
            return;
        }

        $db = new Db($this->oc->db, DB_PREFIX);
        $t = $db->t('mcp_oauth_token');

        if ($addressChanged) {
            $n = (int)$db->value("SELECT COUNT(*) AS c FROM " . $t . " WHERE `revoked` = 0 AND `refresh_expires` > NOW()", 'c');

            if ($n > 0) {
                (new OAuthServer($db, [], ''))->revokeAll();
                $this->revokedNotice = sprintf($this->oc->language->get('ui_revoked_notice'), $n);
            }
        }

        if ($unlimited) {
            $db->exec("UPDATE " . $t . " SET `refresh_expires` = " . $db->q(OAuthServer::FOREVER) . " WHERE `revoked` = 0 AND `refresh_expires` > NOW()");
        } else {
            $db->exec("UPDATE " . $t . " SET `refresh_expires` = " . $db->q(date('Y-m-d H:i:s', time() + OAuthServer::REFRESH_TTL)) . " WHERE `revoked` = 0 AND `refresh_expires` >= " . $db->q(OAuthServer::FOREVER));
        }
    }

    public function tokenIsSet(): bool {
        return (string)$this->oc->config->get('module_mcp_server_token_hash') !== '' || (string)$this->oc->config->get('module_mcp_server_token') !== '';
    }

    /** Module texts in the language picked inside the module (cookie mcp_lang: uk-ua / en-gb), independent of the admin language. */
    public function moduleLanguageCode(): string {
        $cookie = isset($this->oc->request->cookie['mcp_lang']) ? (string)$this->oc->request->cookie['mcp_lang'] : '';

        if (in_array($cookie, array('uk-ua', 'en-gb'), true)) {
            return $cookie;
        }

        $admin = (string)$this->oc->config->get($this->adminLanguageKey);

        return in_array($admin, array('uk-ua', 'en-gb'), true) ? $admin : 'en-gb';
    }

    public function tableExists(string $short): bool {
        $q = $this->oc->db->query("SHOW TABLES LIKE '" . $this->oc->db->escape(DB_PREFIX . $short) . "'");

        return (bool)$q->num_rows;
    }

    /** Settings of a fresh install. */
    public function defaultSettings(): array {
        return [
            'module_mcp_server_path'      => self::newPath(),
            'module_mcp_server_status'    => 1, // enabled on install (the OC4 Modules list greys a disabled module)
            'module_mcp_server_mode'      => 'read_only',
            'module_mcp_server_log'       => 1,
            'module_mcp_server_oauth'     => 1,
            'module_mcp_server_conn_unlimited' => 1, // connections never expire by default (otherwise 30 days from last use)
            'module_mcp_server_oauth_key' => OAuthServer::randomToken(32),
            'module_mcp_server_acl'       => TablePolicy::recommended('read'), // a fresh install starts on the Safe profile
        ];
    }

    /** Code of the copy of the module settings: OpenCart deletes the "module_mcp_server" settings itself when the module is uninstalled. */
    const BACKUP = 'mcp_server_backup';

    /** Install from Extensions > Modules: tables and the defaults that are missing; settings of an earlier install win (from the copy). */
    public function installSettings(): void {
        $installer = new Installer(new Db($this->oc->db, DB_PREFIX));
        $installer->install();
        $installer->ensureStoreIndexes(); // the owner pressed Install: the one moment the store's order tables get the indexes
        $this->oc->load->model('setting/setting');
        $kept = $this->oc->model_setting_setting->getSetting('module_mcp_server');

        if (!$kept) {
            $db = new Db($this->oc->db, DB_PREFIX); // OpenCart 3 and 4 name the model's getter differently
            $copy = json_decode((string)$db->value("SELECT `value` FROM " . $db->t('setting') . " WHERE `store_id` = 0 AND `code` = " . $db->q(self::BACKUP) . " LIMIT 1", 'value'), true);
            $kept = is_array($copy) ? $copy : [];
        }

        if ($kept && !isset($kept['module_mcp_server_path'])) {
            $kept['module_mcp_server_path'] = self::LEGACY_PATH; // an update of a store made before the random address: its AI keeps the address it knows
        }

        $merged = array_merge($this->defaultSettings(), $kept);
        $this->oc->model_setting_setting->editSetting('module_mcp_server', $merged);

        // create the SEO alias right away, so the store's short MCP address (/mcp-xxxx) works from the first install,
        // not only after the owner saves the settings once. (Display still needs the store's SEO URLs to be on.)
        if (is_callable($this->syncAliases)) {
            $path = (string)($merged['module_mcp_server_path'] ?? '');

            if ($path !== '') {
                $merged['module_mcp_server_pretty_urls'] = call_user_func($this->syncAliases, (string)($merged['module_mcp_server_url_secret'] ?? ''), $path) ? 1 : 0;
                $this->oc->model_setting_setting->editSetting('module_mcp_server', $merged);
            }
        }

        $this->mirror();
    }

    /** Keep the copy of the module settings in step (after every save). */
    public function mirror(): void {
        $db = new Db($this->oc->db, DB_PREFIX);
        $all = [];

        foreach ($db->rows("SELECT `key`, `value`, `serialized` FROM " . $db->t('setting') . " WHERE `store_id` = 0 AND `code` = 'module_mcp_server'") as $r) {
            $all[(string)$r['key']] = (int)$r['serialized'] ? json_decode((string)$r['value'], true) : (string)$r['value'];
        }

        $db->exec("DELETE FROM " . $db->t('setting') . " WHERE `store_id` = 0 AND `code` = " . $db->q(self::BACKUP));

        if ($all) {
            $db->exec("INSERT INTO " . $db->t('setting') . " SET `store_id` = 0, `code` = " . $db->q(self::BACKUP) . ", `key` = " . $db->q(self::BACKUP) . ", `value` = " . $db->q((string)json_encode($all, JSON_UNESCAPED_UNICODE)) . ", `serialized` = 0");
        }
    }

    /** What a wipe deletes, for the confirmation dialog: rows per table, the photo trash. */
    public function wipePreview(): array {
        $db = new Db($this->oc->db, DB_PREFIX);

        return ['rows' => (new Installer($db))->rowCounts(), 'trash' => ImageStore::trashStats(defined('DIR_IMAGE') ? DIR_IMAGE : '')];
    }

    private function postAllowed(): ?array {
        if ($this->oc->request->server['REQUEST_METHOD'] != 'POST' || !$this->oc->user->hasPermission('modify', $this->route)) {
            return ['error' => $this->oc->language->get('error_permission')];
        }

        return null;
    }

    /** AJAX (POST, confirm=1): "Clear the module data" - logs, journal and rollbacks, connections, mails, prompts, photo trash, settings; the license stays. */
    public function wipeData(): array {
        if ($e = $this->postAllowed()) {
            return $e;
        }

        if (empty($this->oc->request->post['confirm'])) {
            return ['error' => $this->oc->language->get('ui_wipe_confirm_needed')];
        }

        $stats = $this->removeData(true);
        $this->installSettings();

        return ['success' => sprintf($this->oc->language->get('ui_wipe_done'), $stats['rows'], $stats['trash']['files'], round($stats['trash']['bytes'] / 1048576, 1))] + $stats;
    }

    /** Drop the module's tables, photo trash and settings (the license key too unless kept). Used by both buttons. */
    public function removeData(bool $keepLicense): array {
        $db = new Db($this->oc->db, DB_PREFIX);
        $stats = (new Installer($db))->wipe(defined('DIR_IMAGE') ? DIR_IMAGE : '');
        $this->oc->load->model('setting/setting');

        foreach (array_merge(['module_mcp_server', 'module_mcp_server_content', self::BACKUP], $keepLicense ? [] : ['module_mcp_server_license']) as $code) {
            $this->oc->model_setting_setting->deleteSetting($code);
        }

        return $stats;
    }

    /** "Remove the module completely" is confirmed with the "I understand" box. */
    public function removeConfirmed(): bool {
        return !empty($this->oc->request->post['confirm']);
    }

    public function removeRefusal(): ?array {
        if ($e = $this->postAllowed()) {
            return $e;
        }

        return $this->removeConfirmed() ? null : ['error' => $this->oc->language->get('ui_wipe_confirm_needed')];
    }

    /**
     * Delete files of the module: deepest first, folders only when empty, nothing outside the store. On the dev stands the
     * module folders are the repository (marker .mcp-dev in the core): there the files are only listed.
     * @param string[] $paths absolute paths of files and folders
     */
    public static function deleteFiles(array $paths, string $coreDir): array {
        $dry = is_file(rtrim($coreDir, '/') . '/.mcp-dev');
        $files = [];

        foreach ($paths as $p) {
            if (is_dir($p) && !is_link($p)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($p, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                    $files[] = $f->getPathname();
                }
            }

            $files[] = $p;
        }

        $files = array_values(array_unique($files));
        usort($files, function ($a, $b) { return strlen($b) - strlen($a); });
        $deleted = 0;

        foreach ($files as $f) {
            if ($dry) {
                continue;
            }

            if (is_dir($f) && !is_link($f)) {
                $deleted += @rmdir($f) ? 1 : 0; // only empty folders: a folder with other files of the store stays
            } elseif (file_exists($f) || is_link($f)) {
                $deleted += @unlink($f) ? 1 : 0;
            }
        }

        return ['dry_run' => $dry, 'deleted' => $deleted, 'found' => count(array_filter($files, 'file_exists'))];
    }

    /** Take the module's route out of every user group's permissions (OpenCart 3.0.2 has no removePermissions). */
    public function removePermissions(string $route): void {
        $this->oc->load->model('user/user_group');

        foreach ($this->oc->model_user_user_group->getUserGroups() as $g) {
            $perm = $this->oc->model_user_user_group->getUserGroup((int)$g['user_group_id'])['permission'] ?? [];

            foreach (['access', 'modify'] as $type) {
                // OpenCart's removePermission() prints a warning for a group without this list, which broke the JSON answer on hosts that show errors
                if (isset($perm[$type]) && is_array($perm[$type]) && in_array($route, $perm[$type], true)) {
                    $this->oc->model_user_user_group->removePermission((int)$g['user_group_id'], $type, $route);
                }
            }
        }
    }

    /** AJAX (POST): empty the photo trash. Its deletions can no longer be rolled back. */
    /** Path of the page's styles ("css") or script ("js", per OpenCart version: route separator "/" is OpenCart 3). */
    public static function assetFile(string $f, string $sep): string {
        return __DIR__ . '/assets/' . ($f === 'css' ? 'admin.css' : ($sep === '/' ? 'admin.oc3.js' : 'admin.oc4.js'));
    }

    /** Send the page's styles or script (admin action "asset", behind the admin login like every action here). */
    public static function sendAsset(string $f, string $sep): void {
        $f = $f === 'css' ? 'css' : 'js';
        $file = self::assetFile($f, $sep);
        header('Content-Type: ' . ($f === 'css' ? 'text/css' : 'application/javascript') . '; charset=utf-8');
        header('Cache-Control: private, max-age=31536000, immutable'); // the link changes with the module version
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }

    public function clearTrash(): array {
        if ($e = $this->postAllowed()) {
            return $e;
        }

        $db = new Db($this->oc->db, DB_PREFIX);
        $done = ImageStore::emptyTrash(defined('DIR_IMAGE') ? DIR_IMAGE : '');

        if ($db->tableExists(ImageStore::TRASH)) {
            $db->exec("DELETE FROM " . $db->t(ImageStore::TRASH));
        }

        return ['success' => sprintf($this->oc->language->get('ui_trash_cleared'), $done['files'], round($done['bytes'] / 1048576, 1))] + $done;
    }
}
