<?php
namespace Opencart\Catalog\Controller\Extension\AiAdminOc4\Startup;
/**
 * Runs before OpenCart's maintenance check (setting/startup): the AI connection (MCP, OAuth, upload links) keeps
 * working while the store is closed for visitors. Only these routes; the storefront stays closed.
 */
class McpServer extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$route = isset($this->request->get['route']) ? (string)$this->request->get['route'] : '';

		if (strpos($route, 'extension/ai_admin_oc4/module/mcp_server') === 0) {
			$this->config->set('config_maintenance', 0);
		}
	}
}
