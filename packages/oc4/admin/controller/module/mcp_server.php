<?php
namespace Opencart\Admin\Controller\Extension\AiAdminOc4\Module;

/**
 * OpenCart 4.0.2+ / 4.1.x admin controller: MCP Server settings, table access levels, OAuth connections, log.
 */
class McpServer extends \Opencart\System\Engine\Controller {
	const ROUTE_BASE = 'extension/ai_admin_oc4/module/mcp_server';
	
	const ALIASES = [
		'mcp-oauth-authorize'        => 'oauth_authorize',
		'mcp-oauth-token'            => 'oauth_token',
		'mcp-oauth-register'         => 'oauth_register',
		'.well-known'                => 'oauth_metadata',
		'oauth-protected-resource'   => 'oauth_resource',
		'oauth-authorization-server' => 'oauth_metadata',
		'openid-configuration'       => 'oauth_metadata',
	];

	public function index(): void {
		$this->loadModuleLanguage();
		$this->document->setTitle($this->language->get('heading_title'));
		$this->loadCore();
		(new \OcMcp\Installer(new \OcMcp\Db($this->db, DB_PREFIX)))->upgrade();

		$data = $this->panel()->viewData();
		$token = $this->session->data['user_token'];
		$data['save'] = $this->url->link(self::ROUTE_BASE . self::sep() . 'save', 'user_token=' . $token);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $token . '&type=module');
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/ai_admin_oc4/module/mcp_server', $data));
	}

	/** AJAX (POST): the settings form. */
	public function save(): void {
		$this->loadModuleLanguage();
		$this->loadCore();
		$panel = $this->panel();
		$errors = $panel->validateSave();
		$json = $errors ? ['error' => $errors] : ['success' => $this->language->get('text_success'), 'rev' => $panel->writeSettings()]; // the open form goes on from the new revision

		if (!$errors && $panel->revokedNotice !== '') {
			$json['revoked_notice'] = $panel->revokedNotice; // a new address: the page reloads with the connections gone
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function columns(): void {
		$this->ajax('columns');
	}

	public function revoke(): void {
		$this->ajax('revoke');
	}

	public function check(): void {
		$this->ajax('check');
	}

	public function changes(): void {
		$this->ajax('changes');
	}

	public function rollback_all(): void {
		$this->ajax('rollbackAll');
	}

	public function changeDetails(): void {
		$this->ajax('changeDetails');
	}

	public function rollback(): void {
		$this->ajax('rollback');
	}

	public function cancelChange(): void {
		$this->ajax('cancelChange');
	}

	public function logs(): void {
		$this->ajax('logs');
	}

	public function archive(): void {
		$this->ajax('archive');
	}

	public function clearLog(): void {
		$this->ajax('clearLog');
	}

	public function content(): void {
		$this->ajax('content');
	}

	public function prompts(): void {
		$this->ajax('prompts');
	}

	public function license(): void {
		$this->ajax('license');
	}

	public function rename(): void {
		$this->ajax('rename');
	}

	public function wipePreview(): void {
		$this->ajax('wipePreview');
	}

	public function wipe(): void {
		$this->ajax('wipeData');
	}

	/** Styles and script of the settings page (separate files the browser caches). */
	public function asset(): void {
		$this->loadCore();
		\OcMcp\AdminPanel::sendAsset((string)($this->request->get['f'] ?? ''), self::sep());
		exit();
	}

	public function clearTrash(): void {
		$this->ajax('clearTrash');
	}

	/** AJAX (POST, store_name): remove the module completely - data, settings, permissions, the extension record and every file. */
	public function remove(): void {
		$this->loadModuleLanguage();
		$this->loadCore();
		$panel = $this->panel();
		$json = $panel->removeRefusal();

		if ($json === null) {
			$stats = $panel->removeData(true) /* the license key stays: a new install of the module needs no key */;
			$this->deleteAliases();
			$this->load->model('setting/extension');
			$this->model_setting_extension->uninstall('module', 'mcp_server');
			$panel->removePermissions(self::ROUTE_BASE);
			$install = $this->model_setting_extension->getInstallByCode('ai_admin_oc4');
			$paths = [rtrim(DIR_EXTENSION, '/') . '/ai_admin_oc4', DIR_STORAGE . 'marketplace/ai_admin_oc4.ocmod.zip'];

			if ($install) {
				foreach ($this->model_setting_extension->getPathsByExtensionInstallId((int)$install['extension_install_id']) as $p) {
					$this->model_setting_extension->deletePath((int)$p['extension_path_id']);
				}

				$this->model_setting_extension->deleteInstall((int)$install['extension_install_id']);
			}

			$files = \OcMcp\AdminPanel::deleteFiles($paths, DIR_EXTENSION . 'ai_admin_oc4/system/library/mcp');
			$json = ['success' => sprintf($this->language->get('ui_remove_done'), $stats['rows'], $files['dry_run'] ? 0 : $files['deleted']), 'redirect' => str_replace('&amp;', '&', $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token']))] + $stats + ['files' => $files];
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/** One AJAX action of the shared admin panel, answered as JSON. */
	private function ajax(string $action): void {
		$this->loadModuleLanguage();
		$this->loadCore();

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($this->panel()->$action()));
	}

	private function panel(): \OcMcp\AdminPanel {
		$url = $this->url;
		$token = $this->session->data['user_token'];

		return new \OcMcp\AdminPanel($this, self::ROUTE_BASE, self::sep(),
			function (string $method) use ($url, $token) { return $url->link(self::ROUTE_BASE . self::sep() . $method, 'user_token=' . $token); },
			function (string $route, string $args) use ($url) { return $url->link($route, $args); },
			'config_language_admin',
			function (string $secret, string $path): bool { return $this->syncAliases($secret, $path); });
	}

	/** Download the call log as a text file. */
	public function export(): void {
		$this->loadCore();

		if (!$this->user->hasPermission('access', self::ROUTE_BASE)) {
			$this->response->redirect($this->url->link(self::ROUTE_BASE, 'user_token=' . $this->session->data['user_token']));
			return;
		}

		(new \OcMcp\LogExport(new \OcMcp\Db($this->db, DB_PREFIX)))->send((string)$this->config->get('config_name'), (string)($this->request->get['format'] ?? 'txt'), (string)($this->request->get['range'] ?? 'all'));
		exit();
	}

	/** Called by OpenCart's extension manager, which has already checked the 'modify' permission (OC 4.1.0.0 grants
	 * this module's own permission only after install, so checking it here silently skipped the setup). */
	public function install(): void {
		$this->loadCore();
		$this->panel()->installSettings(); // settings kept from an earlier install win: an update keeps the access, token and connections
	}

	/** Called by OpenCart's extension manager, which has already checked the 'modify' permission (OC 4.1.0.0 grants
	 * this module's own permission only after install, so checking it here silently skipped the setup). */
	public function uninstall(): void {
		$this->loadCore();

		// data and settings stay (an update is uninstall + install); "Remove the module completely" in the module's settings deletes everything
		(new \OcMcp\Installer(new \OcMcp\Db($this->db, DB_PREFIX)))->uninstall();
	}

	/** Module texts in the language picked inside the module (cookie mcp_lang: uk-ua / en-gb), independent of the admin language. */
	private function moduleLanguageCode(): string {
		$cookie = isset($this->request->cookie['mcp_lang']) ? (string)$this->request->cookie['mcp_lang'] : '';

		if (in_array($cookie, array('uk-ua', 'en-gb'), true)) {
			return $cookie;
		}

		$admin = (string)$this->config->get('config_language_admin');

		return in_array($admin, array('uk-ua', 'en-gb'), true) ? $admin : 'en-gb';
	}

	private function loadModuleLanguage(): void {
		$this->load->language('extension/ai_admin_oc4/module/mcp_server');
		$code = $this->moduleLanguageCode();
		$file = DIR_EXTENSION . 'ai_admin_oc4/admin/language/' . $code . '/module/mcp_server.php';

		if (is_file($file)) {
			$_ = array();
			include $file;

			foreach ($_ as $key => $value) {
				$this->language->set($key, $value);
			}
		}
	}

	/** Route method separator: "." since OpenCart 4.0.2.0, "|" in 4.0.0.0 - 4.0.1.x. */
	private static function sep(): string {
		return version_compare(VERSION, '4.0.2.0', '<') ? '|' : '.';
	}

	private function loadCore(): void {
		require_once DIR_EXTENSION . 'ai_admin_oc4/system/library/mcp/autoload.php';
	}

	private function deleteAliases(): void {
		try {
			$this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE `key` = 'route' AND (`value` LIKE '" . $this->db->escape(self::ROUTE_BASE) . ".%' OR `value` LIKE '" . $this->db->escape(self::ROUTE_BASE) . "|%')");
		} catch (\Throwable $e) {
		}
	}

	/** SEO aliases: the store's MCP address (its own path), the url-secret and OAuth discovery. Returns true when written. */
	private function syncAliases(string $urlSecret, string $path): bool {
		try {
			$this->deleteAliases();

			$aliases = [$path => 'endpoint'] + self::ALIASES;

			if ($urlSecret !== '') {
				$aliases[$urlSecret] = 'endpoint';
			}

			$stores = [0];
			$query = $this->db->query("SELECT `store_id` FROM `" . DB_PREFIX . "store`");

			foreach ($query->rows as $row) {
				$stores[] = (int)$row['store_id'];
			}

			$languages = $this->db->query("SELECT `language_id` FROM `" . DB_PREFIX . "language`");

			foreach ($stores as $store_id) {
				foreach ($languages->rows as $language) {
					foreach ($aliases as $keyword => $method) {
						$this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET `store_id` = '" . (int)$store_id . "', `language_id` = '" . (int)$language['language_id'] . "', `key` = 'route', `value` = '" . $this->db->escape(self::ROUTE_BASE . self::sep() . '' . $method) . "', `keyword` = '" . $this->db->escape($keyword) . "', `sort_order` = 0");
					}
				}
			}

			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}
}
