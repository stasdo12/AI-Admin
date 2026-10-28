<?php
/**
 * OpenCart 3.0.x catalog controller: MCP endpoint + OAuth 2.1 authorization server.
 *
 *   POST  index.php?route=extension/module/mcp_server/endpoint        (alias: /mcp, /<url-secret>)
 *   GET   index.php?route=extension/module/mcp_server/oauth_resource  (alias: /.well-known/oauth-protected-resource)
 *   GET   index.php?route=extension/module/mcp_server/oauth_metadata  (alias: /.well-known/oauth-authorization-server)
 *   GET/POST .../oauth_authorize  (alias: /mcp-oauth-authorize)   .../oauth_token (/mcp-oauth-token)   .../oauth_register (/mcp-oauth-register)
 */
class ControllerExtensionModuleMcpServer extends Controller {
	const ROUTE = 'extension/module/mcp_server';

	/** Layout module hook - this extension renders nothing in the storefront. */
	public function index($setting = array()) {
		return '';
	}

	public function endpoint() {
		$this->boot();

		// path-based discovery documents that end up on the endpoint alias (/.well-known/.../mcp)
		$route = isset($this->request->get['_route_']) ? (string)$this->request->get['_route_'] : '';

		if (strpos($route, '.well-known/') === 0) {
			$this->wellKnown($route);
		}

		$ctx = $this->context();
		$oauth = $this->oauthEnabled() ? $this->oauth($ctx->logger) : null;

		if ($oauth) {
			$ctx->resourceMetadataUrl = $this->urls()['resource_metadata'];
		}

		$auth = new \OcMcp\Auth(
			(string)$this->config->get('module_mcp_server_token'),
			(string)$this->config->get('module_mcp_server_url_secret'),
			(string)$this->config->get('module_mcp_server_allowed_ips'),
			$oauth ? array($oauth, 'validateBearer') : null,
			(string)$this->config->get('module_mcp_server_token_hash')
		);

		$origins = preg_split('/[\s,]+/', (string)$this->config->get('module_mcp_server_origins'));

		$request = \OcMcp\HttpRequest::fromGlobals();
		$request->routeKeyword = $route;

		$server = new \OcMcp\Server($ctx, \OcMcp\ToolRegistry::createDefault(), $auth, array_filter($origins));
		$server->handle($request)->send();
		exit();
	}

	public function oauth_resource() {
		$this->boot();
		$this->requireOauth();
		\OcMcp\OAuthServer::jsonPublic(200, $this->oauth()->protectedResourceMetadata())->send();
		exit();
	}

	public function oauth_metadata() {
		$this->boot();
		$this->requireOauth();

		$route = isset($this->request->get['_route_']) ? (string)$this->request->get['_route_'] : '';

		if (strpos($route, '.well-known/oauth-protected-resource') === 0) {
			\OcMcp\OAuthServer::jsonPublic(200, $this->oauth()->protectedResourceMetadata())->send();
			exit();
		}

		\OcMcp\OAuthServer::jsonPublic(200, $this->oauth()->authorizationServerMetadata())->send();
		exit();
	}

	public function oauth_register() {
		$this->boot();
		$this->requireOauth();
		$this->oauth($this->logger())->handleRegister(\OcMcp\HttpRequest::fromGlobals())->send();
		exit();
	}

	public function oauth_token() {
		$this->boot();
		$this->requireOauth();
		$this->oauth($this->logger())->handleToken(\OcMcp\HttpRequest::fromGlobals())->send();
		exit();
	}

	public function oauth_authorize() {
		$this->boot();
		$this->requireOauth();

		$db = $this->db;
		$verifyAdmin = function ($username, $password) use ($db) {
			$query = $db->query("SELECT `user_id`, `user_group_id`, `username`, `password`, `salt`, `firstname`, `lastname`, `status` FROM `" . DB_PREFIX . "user` WHERE `username` = '" . $db->escape($username) . "' LIMIT 1");

			if (!$query->num_rows || !(int)$query->row['status']) {
				return null;
			}

			$row = $query->row;
			$ok = false;

			if (strlen((string)$row['password']) >= 40 && (string)$row['salt'] !== '') {
				$ok = hash_equals((string)$row['password'], sha1($row['salt'] . sha1($row['salt'] . sha1($password))));
			}

			if (!$ok && function_exists('password_verify') && strpos((string)$row['password'], '$') === 0) {
				$ok = password_verify($password, (string)$row['password']);
			}

			if (!$ok && strlen((string)$row['password']) === 32) {
				$ok = hash_equals((string)$row['password'], md5($password));
			}

			if (!$ok) {
				return null;
			}

			// only an administrator who may change this module grants an AI access to the store (not any active account)
			$group = $db->query("SELECT `permission` FROM `" . DB_PREFIX . "user_group` WHERE `user_group_id` = '" . (int)$row['user_group_id'] . "'");
			$perm = $group->num_rows ? json_decode((string)$group->row['permission'], true) : null;

			if (!is_array($perm) || !in_array('extension/module/mcp_server', isset($perm['modify']) ? (array)$perm['modify'] : array(), true)) {
				return array('no_permission' => true);
			}

			return array('user_id' => (int)$row['user_id'], 'username' => $row['username'], 'name' => trim($row['firstname'] . ' ' . $row['lastname']));
		};

		$strings = $this->pageStrings();
		$render = function (array $view) use ($strings) {
			return \OcMcp\OAuthPage::render($view, $strings);
		};

		$this->oauth($this->logger())->handleAuthorize(\OcMcp\HttpRequest::fromGlobals(), $verifyAdmin, $render)->send(); // rights come from the Access tab at call time
		exit();
	}

	/** CSV export download (export_data): no login, the signed link is the key; the file obeys the Access tab. */
	public function download() {
		$this->boot();
		$ctx = $this->context();
		\OcMcp\DataExport::serve($ctx, isset($this->request->get['t']) ? (string)$this->request->get['t'] : '', \OcMcp\DataExport::key($ctx));
		exit();
	}

	/** One-time phone upload page (create_upload_link): no login, the secret link is the key. */
	public function upload() {
		$this->boot();
		$ctx = $this->context();
		$settings = $ctx->content()->settings();
		$page = new \OcMcp\UploadPage($ctx->db, function ($batch) use ($ctx) {
			$ctx->journal = new \OcMcp\ChangeJournal($ctx->db, 'phone upload', 'upload', $ctx->deleteDelay, $batch);

			return $ctx->images();
		}, isset($this->request->server['HTTP_ACCEPT_LANGUAGE']) ? (string)$this->request->server['HTTP_ACCEPT_LANGUAGE'] : '', (string)$this->config->get('config_language'), \OcMcp\ContentPolicy::IMG_WIDTH, defined('DIR_STORAGE') ? DIR_STORAGE : DIR_SYSTEM . 'storage/');
		$file = isset($_FILES['photo']) && is_array($_FILES['photo']) ? $_FILES['photo'] : (isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : null);
		$page->handle(isset($this->request->get['t']) ? (string)$this->request->get['t'] : '', (string)$this->request->server['REQUEST_METHOD'], $file, html_entity_decode((string)$this->config->get('config_name'), ENT_QUOTES, 'UTF-8'), \OcMcp\ContentPolicy::IMG_HOURLY)->send();
		exit();
	}

	// ------------------------------------------------------------------ helpers

	private function boot() {
		require_once DIR_SYSTEM . 'library/mcp/autoload.php';

		@set_time_limit(60);
		@ini_set('display_errors', '0');
		ob_start();

		if (!$this->config->get('module_mcp_server_status')) {
			\OcMcp\HttpResponse::json(404, array('jsonrpc' => '2.0', 'id' => null, 'error' => array('code' => -32601, 'message' => 'MCP server is disabled in the store settings')))->send();
			exit();
		}

		// license: the MCP endpoint and the OAuth pages answer 403 until the activation key is entered in the admin
		// (FREE build: the universal key 'ai-admin-free' is valid on any domain - the owner enters it to activate)
		$license = (new \OcMcp\License())->check((string)$this->config->get('module_mcp_server_license_key'), \OcMcp\License::storeHost(defined('HTTPS_SERVER') && HTTPS_SERVER ? HTTPS_SERVER : HTTP_SERVER));

		if (!$license['valid']) {
			\OcMcp\HttpResponse::json(403, array('jsonrpc' => '2.0', 'id' => null, 'error' => array('code' => -32001, 'message' => 'The MCP server module of this store has no valid license for this domain (' . $license['reason'] . '). The store owner enters the license key in the admin: Extensions > Modules > AI Admin.')))->send();
			exit();
		}
	}

	private function oauthEnabled() {
		$v = $this->config->get('module_mcp_server_oauth');

		return $v === null || $v === '' || (bool)$v;
	}

	private function requireOauth() {
		if (!$this->oauthEnabled()) {
			\OcMcp\HttpResponse::json(404, array('error' => 'oauth_disabled'))->send();
			exit();
		}
	}

	private function wellKnown($route) {
		$this->requireOauth();

		if (strpos($route, '.well-known/oauth-protected-resource') === 0) {
			\OcMcp\OAuthServer::jsonPublic(200, $this->oauth()->protectedResourceMetadata())->send();
		} else {
			\OcMcp\OAuthServer::jsonPublic(200, $this->oauth()->authorizationServerMetadata())->send();
		}

		exit();
	}

	private function base() {
		$base = (defined('HTTPS_SERVER') && strpos(HTTPS_SERVER, 'https://') === 0) ? HTTPS_SERVER : HTTP_SERVER;

		return rtrim($base, '/');
	}

	public function urls() {
		$base = $this->base();
		$pretty = (bool)$this->config->get('module_mcp_server_pretty_urls') && (bool)$this->config->get('config_seo_url');

		if ($pretty) {
			return array(
				'issuer'            => $base,
				'resource'          => $base . '/' . ((string)$this->config->get('module_mcp_server_path') !== '' ? (string)$this->config->get('module_mcp_server_path') : 'mcp'), // the store's own address; "mcp" for stores set up before the random one
				'authorize'         => $base . '/mcp-oauth-authorize',
				'token'             => $base . '/mcp-oauth-token',
				'register'          => $base . '/mcp-oauth-register',
				'resource_metadata' => $base . '/.well-known/oauth-protected-resource',
				'as_metadata'       => $base . '/.well-known/oauth-authorization-server',
			);
		}

		$q = $base . '/index.php?route=' . self::ROUTE . '/';

		return array(
			'issuer'            => $base,
			'resource'          => $q . 'endpoint',
			'authorize'         => $q . 'oauth_authorize',
			'token'             => $q . 'oauth_token',
			'register'          => $q . 'oauth_register',
			'resource_metadata' => $q . 'oauth_resource',
			'as_metadata'       => $q . 'oauth_metadata',
		);
	}

	private function signingKey() {
		$key = (string)$this->config->get('module_mcp_server_oauth_key');
		$base = $key !== '' ? $key : hash('sha256', 'mcp|' . DB_DATABASE . '|' . DB_PREFIX . '|' . (defined('DB_PASSWORD') ? DB_PASSWORD : ''));

		return \OcMcp\License::mix(new \OcMcp\Db($this->db, DB_PREFIX), $base); // OAuth consent CSRF is bound to the store's license (see License::mix)
	}

	private function logger() {
		return new \OcMcp\Logger(new \OcMcp\Db($this->db, DB_PREFIX), true); // the request log is always kept (no switch in the settings)
	}

	private function oauth($logger = null) {
		return (new \OcMcp\OAuthServer(new \OcMcp\Db($this->db, DB_PREFIX), $this->urls(), $this->signingKey(), (string)$this->config->get('config_name'), $logger ?: $this->logger()))->unlimited((bool)$this->config->get('module_mcp_server_conn_unlimited'));
	}

	private function context() {
		$db = new \OcMcp\Db($this->db, DB_PREFIX);
		$config = $this->config;
		$ctx = new \OcMcp\Context($db, function ($key) use ($config) {
			return $config->get($key);
		});

		$ctx->cache = $this->cache;
		$ctx->ocVersion = defined('VERSION') ? VERSION : '3.0';
		// one source of truth: the mode follows the saved Access tab on every request (a section at Edit or Full -> manage, otherwise read_only).
		// The stored module_mcp_server_mode is only what the last save computed; raw SQL writes (developer) are retired for good.
		$acl = $this->config->get('module_mcp_server_acl');
		if (\OcMcp\Version::FREE) {
			$acl = \OcMcp\TablePolicy::recommended('read'); // FREE build: locked on the Safe (read-only) profile, whatever is stored
		}
		$ctx->mode = \OcMcp\TablePolicy::deriveMode(is_array($acl) ? $acl : (is_string($acl) && $acl !== '' ? (array)json_decode($acl, true) : array()));
		$ctx->languageId = (int)$this->config->get('config_language_id');
		$ctx->storeId = (int)$this->config->get('config_store_id');
		$ctx->customerGroupId = (int)$this->config->get('config_customer_group_id');
		$ctx->currency = (string)$this->config->get('config_currency');
		$ctx->storeName = (string)$this->config->get('config_name');
		$ctx->storeUrl = $this->base() . '/';
		$ctx->ip = isset($this->request->server['REMOTE_ADDR']) ? $this->request->server['REMOTE_ADDR'] : '';
		$ctx->logger = $this->logger();
		$ctx->imageDir = DIR_IMAGE;
		$ctx->systemDir = DIR_SYSTEM;
		$ctx->storageDir = defined('DIR_STORAGE') ? DIR_STORAGE : DIR_SYSTEM . 'storage/';
		$base = $this->base();
		$ctx->uploadUrl = function ($token) use ($base) {
			return $base . '/index.php?route=' . self::ROUTE . '/upload&t=' . rawurlencode($token);
		};
		$ctx->downloadUrl = function ($token) use ($base) {
			return $base . '/index.php?route=' . self::ROUTE . '/download&t=' . rawurlencode($token);
		};
		$ctx->policy = (new \OcMcp\TablePolicy(DB_PREFIX, $acl))->setRequired($this->config->get('module_mcp_server_required'));
		$ctx->rateLimiter = new \OcMcp\RateLimiter($db);
		$delay = 0; // deletions are immediate: every one is in the journal and rolls back
		$ctx->deleteDelay = ($delay === null || $delay === '') ? 10 : (int)$delay;
		$limit = $this->config->get('module_mcp_server_rate_limit');
		$ctx->rateLimit = ($limit === null || $limit === '') ? 300 : (int)$limit;

		$config = $this->config;
		$ctx->mailer = function ($to, $subject, $html, $text) use ($config) {
			$mail = new \Mail($config->get('config_mail_engine'));
			$mail->parameter = $config->get('config_mail_parameter');
			$mail->smtp_hostname = $config->get('config_mail_smtp_hostname');
			$mail->smtp_username = $config->get('config_mail_smtp_username');
			$mail->smtp_password = html_entity_decode((string)$config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
			$mail->smtp_port = $config->get('config_mail_smtp_port');
			$mail->smtp_timeout = $config->get('config_mail_smtp_timeout');
			$mail->setTo($to);
			$mail->setFrom($config->get('config_email'));
			$mail->setSender(html_entity_decode((string)$config->get('config_name'), ENT_QUOTES, 'UTF-8'));
			$mail->setSubject(html_entity_decode($subject, ENT_QUOTES, 'UTF-8'));
			$mail->setHtml($html);
			$mail->setText($text);
			$mail->send();
		};
		$hourly = $this->config->get('module_mcp_server_mail_hourly');
		$ctx->mailHourly = ($hourly === null || $hourly === '') ? 60 : (int)$hourly;
		$pii = $this->config->get('module_mcp_server_pii_hourly');
		$ctx->piiHourly = ($pii === null || $pii === '') ? 2000 : (int)$pii;

		$registry = $this;
		$ctx->orderHistoryHandler = function ($order_id, $order_status_id, $comment, $notify) use ($registry) {
			$registry->load->model('checkout/order');
			$registry->model_checkout_order->addOrderHistory((int)$order_id, (int)$order_status_id, (string)$comment, (bool)$notify);
		};
		// create_order: the store's tax library and checkout model do the money, the stock and the e-mails
		$ctx->orderApi = array(
			'address' => function ($country_id, $zone_id) use ($registry) { $registry->tax->setShippingAddress((int)$country_id, (int)$zone_id); $registry->tax->setPaymentAddress((int)$country_id, (int)$zone_id); },
			'tax'     => function ($value, $tax_class_id) use ($registry) { return (float)$registry->tax->getTax((float)$value, (int)$tax_class_id); },
			'rates'   => function ($value, $tax_class_id) use ($registry) { return (array)$registry->tax->getRates((float)$value, (int)$tax_class_id); },
			'create'  => function ($data) use ($registry) { $registry->load->model('checkout/order'); return (int)$registry->model_checkout_order->addOrder($data); },
			'history' => function ($order_id, $order_status_id, $comment, $notify) use ($registry) { $registry->load->model('checkout/order'); $registry->model_checkout_order->addOrderHistory((int)$order_id, (int)$order_status_id, (string)$comment, (bool)$notify); },
		);

		return $ctx;
	}

	/**
	 * Strings of the OAuth sign-in page in the visitor's browser language (uk / en), not the storefront language:
	 * the first of uk / en in Accept-Language wins; otherwise the store language when it is uk-ua; otherwise English.
	 */
	private function pageStrings() {
		$code = '';

		foreach (explode(',', strtolower(isset($this->request->server['HTTP_ACCEPT_LANGUAGE']) ? (string)$this->request->server['HTTP_ACCEPT_LANGUAGE'] : '')) as $part) {
			$tag = substr(trim(explode(';', $part)[0]), 0, 2);

			if ($tag === 'uk' || $tag === 'en') {
				$code = $tag === 'uk' ? 'uk-ua' : 'en-gb';
				break;
			}
		}

		if ($code === '') {
			$code = (string)$this->config->get('config_language') === 'uk-ua' ? 'uk-ua' : 'en-gb';
		}

		$out = array();
		$file = DIR_LANGUAGE . $code . '/extension/module/mcp_server.php';

		if (is_file($file)) {
			$_ = array();
			include $file;

			foreach (array_keys(\OcMcp\OAuthPage::strings()) as $key) {
				if (isset($_['oauth_' . $key])) {
					$out[$key] = $_['oauth_' . $key];
				}
			}
		}

		return $out; // missing keys fall back to the English defaults of OAuthPage
	}
}
