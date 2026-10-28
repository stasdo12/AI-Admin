<?php
namespace OcMcp;

/**
 * Ordered registry of tools. Order is deterministic (helps clients cache tool lists).
 */
final class ToolRegistry {
    /** @var Tool[] name => tool */
    private $tools = [];

    public function register(Tool $tool): self {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    public function get(string $name): ?Tool {
        return $this->tools[$name] ?? null;
    }

    /**
     * Tool definitions for tools/list. The list is the same whatever the Access tab says: clients cache it, so a section the owner
     * opens later works in the same chat; every call is checked against the Access tab when it happens (Server::callTool).
     * Left out only: tools whose table this OpenCart version lacks (vouchers in 4.1).
     */
    public function definitions(Context $ctx): array {
        $out = [];

        $multi = $ctx->multiStore();

        foreach ($this->tools as $tool) {
            if (self::tablesExist($tool, $ctx)) {
                $d = $tool->definition();

                if (!$multi) {
                    $d = self::withoutStoreArgs($d); // one store: the store arguments would only cost tokens and invite mistakes
                }

                $out[] = $d;
            }
        }

        return $out;
    }

    /** A definition without its store arguments (store_id, stores, and the store_id of a filter object). */
    private static function withoutStoreArgs(array $d): array {
        $props = $d['inputSchema']['properties'] ?? null;

        if (!is_array($props)) {
            return $d;
        }

        unset($props['store_id'], $props['stores']);

        if (isset($props['filter']['properties']) && is_array($props['filter']['properties'])) {
            unset($props['filter']['properties']['store_id']);
        }

        $d['inputSchema']['properties'] = $props ?: new \stdClass();

        if (isset($d['inputSchema']['required'])) {
            $d['inputSchema']['required'] = array_values(array_diff($d['inputSchema']['required'], ['store_id', 'stores']));
        }

        return $d;
    }

    public static function tablesExist(Tool $tool, Context $ctx): bool {
        foreach ($tool->tables() as $t) {
            if (!$ctx->hasTable($t)) {
                return false; // this OpenCart version has no such table (e.g. vouchers in 4.1)
            }
        }

        return true;
    }

    /**
     * FREE / demo build: READ-ONLY tool set. Every write tool (save_content, update_product,
     * bulk_update_products, manage_records, manage_customer, manage_review, update_settings,
     * import_data, order writes, mail, image writes, prompts and the change-history rollback)
     * is removed from this distribution - its PHP ships only in the paid build. The AI can read
     * the whole store but can change nothing.
     */
    public static function createDefault(): self {
        $r = new self();

        $r->register(new Tools\StoreInfo());
        $r->register(new Tools\GetSettings());
        $r->register(new Tools\ListProducts());
        $r->register(new Tools\GetProduct());
        $r->register(new Tools\ExportData());
        $r->register(new Tools\ContentGuidelines());
        $r->register(new Tools\GetContent());
        $r->register(new Tools\ListImages());
        $r->register(new Tools\ListCategories());
        $r->register(new Tools\ListOrders());
        $r->register(new Tools\GetOrder());
        $r->register(new Tools\ListCustomers());
        $r->register(new Tools\GetCustomer());
        $r->register(new Tools\ListVouchers());
        $r->register(new Tools\SalesReport());
        $r->register(new Tools\LowStock());
        $r->register(new Tools\DbSchema());
        $r->register(new Tools\SqlQuery());

        return $r;
    }
}
