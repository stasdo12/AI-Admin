<?php
/**
 * OpenCart 3.0.x admin controller: MCP Server settings, table access levels, OAuth connections, log.
 */
class ControllerExtensionModuleMcpServer extends Controller {
	const ROUTE_BASE = 'extension/module/mcp_server';
	const ROUTE_ENDPOINT = 'extension/module/mcp_server/endpoint';

	/** SEO keyword => controller method (pretty URLs for the MCP endpoint and OAuth discovery) */
	const ALIASES = array(
		'mcp-oauth-authorize'        => 'oauth_authorize',
		'mcp-oauth-token'            => 'oauth_token',
		'mcp-oauth-register'         => 'oauth_register',
		'.well-known'                => 'oauth_metadata',
		'oauth-protected-resource'   => 'oauth_resource',
		'oauth-authorization-server' => 'oauth_metadata',
		'openid-configuration'       => 'oauth_metadata',
	);

	public function index() {
		$this->loadModuleLanguage();
		$this->document->setTitle($this->language->get('heading_title'));
		$this->loadCore();
		(new \OcMcp\Installer(new \OcMcp\Db($this->db, DB_PREFIX)))->upgrade();
		$panel = $this->panel();
		$errors = array();

		if ($this->request->server['REQUEST_METHOD'] == 'POST') {
			$errors = $panel->validateSave();

			if (!$errors) {
				$panel->writeSettings();
				$this->session->data['success'] = $this->language->get('text_success') . ($panel->revokedNotice !== '' ? ' ' . $panel->revokedNotice : ''); // a new address revoked the connections
				$this->response->redirect($this->url->link(self::ROUTE_BASE, 'user_token=' . $this->session->data['user_token'], true));
			}
		}

		$data = $panel->viewData();
		$token = $this->session->data['user_token'];

		foreach (array('warning', 'token', 'url_secret', 'acl', 'path') as $key) {
			$data['error_' . $key] = isset($errors[$key]) ? $errors[$key] : '';
		}

		$data['success'] = isset($this->session->data['success']) ? $this->session->data['success'] : '';
		unset($this->session->data['success']);
		$data['action'] = $this->url->link(self::ROUTE_BASE, 'user_token=' . $token, true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $token . '&type=module', true);
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/mcp_server', $data));
	}

	public function columns() {
		$this->ajax('columns');
	}

	public function revoke() {
		$this->ajax('revoke');
	}

	public function check() {
		$this->ajax('check');
	}

	public function changes() {
		$this->ajax('changes');
	}

	public function rollback_all() {
		$this->ajax('rollbackAll');
	}

	public function changeDetails() {
		$this->ajax('changeDetails');
	}

	public function rollback() {
		$this->ajax('rollback');
	}

	public function cancelChange() {
		$this->ajax('cancelChange');
	}

	public function logs() {
		$this->ajax('logs');
	}

	public function archive() {
		$this->ajax('archive');
	}

	public function clearLog() {
		$this->ajax('clearLog');
	}

	public function content() {
		$this->ajax('content');
	}

	public function prompts() {
		$this->ajax('prompts');
	}

	public function license() {
		$this->ajax('license');
	}

	public function rename() {
		$this->ajax('rename');
	}

	public function wipePreview() {
		$this->ajax('wipePreview');
	}

	public function wipe() {
		$this->ajax('wipeData');
	}

	/** Styles and script of the settings page (separate files the browser caches). */
	public function asset() {
		$this->loadCore();
		\OcMcp\AdminPanel::sendAsset((string)($this->request->get['f'] ?? ''), '/');
		exit();
	}

	public function clearTrash() {
		$this->ajax('clearTrash');
	}

	/** AJAX (POST, store_name): remove the module completely - data, settings, permissions, the extension record and every file. */
	public function remove() {
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
			$rel = array();

			foreach ($this->db->query("SELECT `extension_install_id` FROM `" . DB_PREFIX . "extension_install` WHERE `filename` LIKE 'mcp_server%'")->rows as $install) {
				foreach ($this->model_setting_extension->getExtensionPathsByExtensionInstallId((int)$install['extension_install_id']) as $p) {
					$rel[] = $p['path'];
					$this->model_setting_extension->deleteExtensionPath((int)$p['extension_path_id']);
				}

				$this->model_setting_extension->deleteExtensionInstall((int)$install['extension_install_id']);
			}

			// installed by hand (FTP): the module's own files
			$rel = array_merge($rel, array('admin/controller/extension/module/mcp_server.php', 'admin/language/en-gb/extension/module/mcp_server.php', 'admin/language/uk-ua/extension/module/mcp_server.php',
				'admin/view/template/extension/module/mcp_server.twig', 'catalog/controller/extension/module/mcp_server.php', 'catalog/language/en-gb/extension/module/mcp_server.php',
				'catalog/language/uk-ua/extension/module/mcp_server.php', 'system/library/mcp'));
			$paths = array();

			foreach (array_unique($rel) as $r) {
				$base = strpos($r, 'admin/') === 0 ? DIR_APPLICATION . substr($r, 6) : (strpos($r, 'catalog/') === 0 ? DIR_CATALOG . substr($r, 8) : (strpos($r, 'system/') === 0 ? DIR_SYSTEM . substr($r, 7) : ''));

				if ($base !== '' && (strpos($r, 'mcp') !== false)) { // only the module's own paths, never a shared folder like admin/controller
					$paths[] = rtrim($base, '/');
				}
			}

			$files = \OcMcp\AdminPanel::deleteFiles($paths, DIR_SYSTEM . 'library/mcp');
			$json = array('success' => sprintf($this->language->get('ui_remove_done'), $stats['rows'], $files['dry_run'] ? 0 : $files['deleted']), 'redirect' => str_replace('&amp;', '&', $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true))) + $stats + array('files' => $files);
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/** One AJAX action of the shared admin panel, answered as JSON. */
	private function ajax($action) {
		$this->loadModuleLanguage();
		$this->loadCore();

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($this->panel()->$action()));
	}

	private function panel() {
		$url = $this->url;
		$token = $this->session->data['user_token'];
		$self = $this;

		return new \OcMcp\AdminPanel($this, self::ROUTE_BASE, '/',
			function ($method) use ($url, $token) { return $url->link(self::ROUTE_BASE . '/' . $method, 'user_token=' . $token, true); },
			function ($route, $args) use ($url) { return $url->link($route, $args, true); },
			'config_admin_language',
			function ($secret, $path) use ($self) { return $self->syncAliasesFor($secret, $path); });
	}

	/** For the panel's save (the alias table is version specific). */
	public function syncAliasesFor($secret, $path) {
		return $this->syncAliases((string)$secret, (string)$path);
	}

	/** Download the call log as a text file (newest last). */
	public function export() {
		$this->loadCore();

		if (!$this->user->hasPermission('access', self::ROUTE_BASE)) {
			$this->response->redirect($this->url->link(self::ROUTE_BASE, 'user_token=' . $this->session->data['user_token'], true));
			return;
		}

		$exporter = new \OcMcp\LogExport(new \OcMcp\Db($this->db, DB_PREFIX));
		$exporter->send((string)$this->config->get('config_name'), isset($this->request->get['format']) ? (string)$this->request->get['format'] : 'txt', isset($this->request->get['range']) ? (string)$this->request->get['range'] : 'all');
		exit();
	}

	public function install() {
		$this->loadCore();
		$this->panel()->installSettings(); // settings kept from an earlier install win: an update keeps the access, token and connections
	}

	public function uninstall() {
		$this->loadCore();
		// data and settings stay (an update is uninstall + install); "Remove the module completely" in the module's settings deletes everything
		(new \OcMcp\Installer(new \OcMcp\Db($this->db, DB_PREFIX)))->uninstall();
	}

	/** Module texts in the language picked inside the module (cookie mcp_lang: uk-ua / en-gb), independent of the admin language. */
	private function moduleLanguageCode() {
		$cookie = isset($this->request->cookie['mcp_lang']) ? (string)$this->request->cookie['mcp_lang'] : '';

		if (in_array($cookie, array('uk-ua', 'en-gb'), true)) {
			return $cookie;
		}

		$admin = (string)$this->config->get('config_admin_language');

		return in_array($admin, array('uk-ua', 'en-gb'), true) ? $admin : 'en-gb';
	}

	private function loadModuleLanguage() {
		$this->load->language('extension/module/mcp_server');
		$code = $this->moduleLanguageCode();
		$file = DIR_LANGUAGE . $code . '/extension/module/mcp_server.php';

		if (is_file($file)) {
			$_ = array();
			include $file;

			foreach ($_ as $key => $value) {
				$this->language->set($key, $value);
			}
		}
	}

	private function loadCore() {
		require_once DIR_SYSTEM . 'library/mcp/autoload.php';
	}

	private function deleteAliases() {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE `query` LIKE '" . $this->db->escape(self::ROUTE_BASE) . "/%'");
	}

	/** SEO aliases: the store's MCP address (its own path), the url-secret and OAuth discovery. Returns true when written. */
	private function syncAliases($urlSecret, $path) {
		try {
			$this->deleteAliases();

			$aliases = array($path => 'endpoint') + self::ALIASES;

			if ($urlSecret !== '') {
				$aliases[$urlSecret] = 'endpoint';
			}

			$stores = array(0);
			$query = $this->db->query("SELECT `store_id` FROM `" . DB_PREFIX . "store`");

			foreach ($query->rows as $row) {
				$stores[] = (int)$row['store_id'];
			}

			$languages = $this->db->query("SELECT `language_id` FROM `" . DB_PREFIX . "language`");

			foreach ($stores as $store_id) {
				foreach ($languages->rows as $language) {
					foreach ($aliases as $keyword => $method) {
						$this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET `store_id` = '" . (int)$store_id . "', `language_id` = '" . (int)$language['language_id'] . "', `query` = '" . $this->db->escape(self::ROUTE_BASE . '/' . $method) . "', `keyword` = '" . $this->db->escape($keyword) . "'");
					}
				}
			}

			return true;
		} catch (\Exception $e) {
			return false;
		}
	}
}
