<?php
namespace OcMcp;

/**
 * FREE / demo build: STUB. The shipped prompt library (prompt texts, sections, render/save/reset logic)
 * is a paid-only feature and ships only in the paid build. In the free build there are no prompts: the
 * "Prompts" admin tab stays visible but is disabled, and MCP prompts/list is empty.
 *
 * The class, its constants and every method signature are kept so the core (Server, Context, Installer,
 * AdminContent, the controllers) compiles and runs unchanged - the bodies are inert.
 */
final class PromptLibrary {
    const TABLE = 'mcp_prompt';
    const SECTIONS = 'mcp_prompt_section';
    const BUILTIN_SECTIONS = ['content' => ['uk' => 'Контент і SEO', 'en' => 'Content and SEO'], 'stock' => ['uk' => 'Склад', 'en' => 'Stock'],
                              'analytics' => ['uk' => 'Аналітика', 'en' => 'Analytics']];

    /**
     * FREE build: card metadata ONLY (title / description / section) so the grayed-out "Prompts" tab shows
     * what the paid version offers. The prompt BODIES (the actual instructions - the IP) are not here and do
     * not ship. These prompts do nothing in the free build; the tab is disabled.
     */
    const SHOWCASE = [
        ['name' => 'product', 'section' => 'content', 'title' => ['uk' => 'Товар', 'en' => 'Product'],
            'description' => ['uk' => 'Створити або змінити товар: тексти на всіх мовах, SEO, фото, категорія, ціна', 'en' => 'Create or edit a product: texts in every language, SEO, photos, category, price']],
        ['name' => 'category', 'section' => 'content', 'title' => ['uk' => 'Категорія', 'en' => 'Category'],
            'description' => ['uk' => 'Створити або змінити категорію: місце в дереві, тексти, SEO, H1, картинка, меню', 'en' => 'Create or edit a category: place in the tree, texts, SEO, H1, picture, menu']],
        ['name' => 'manufacturer', 'section' => 'content', 'title' => ['uk' => 'Бренд', 'en' => 'Brand'],
            'description' => ['uk' => 'Створити або змінити бренд: опис, SEO, H1, логотип', 'en' => 'Create or edit a brand: description, SEO, H1, logo']],
        ['name' => 'blog', 'section' => 'content', 'title' => ['uk' => 'Стаття в блог', 'en' => 'Blog article'],
            'description' => ['uk' => 'Написати або оновити статтю з посиланнями на товари і фото', 'en' => 'Write or update an article with links to products and photos']],
        ['name' => 'stock', 'section' => 'stock', 'title' => ['uk' => 'Склад і залишки', 'en' => 'Stock'],
            'description' => ['uk' => 'Що закінчується, що не продається, що дозамовити', 'en' => 'What runs out, what does not sell, what to reorder']],
        ['name' => 'monthly_report', 'section' => 'analytics', 'title' => ['uk' => 'Звіт за місяць', 'en' => 'Monthly report'],
            'description' => ['uk' => 'Продажі, замовлення, клієнти, товари і висновки', 'en' => 'Sales, orders, customers, products and takeaways']],
    ];

    /** @var Db */
    private $db;
    /** @var string uk | en */
    private $lang;

    public function __construct(Db $db, string $lang, array $unused = []) {
        $this->db = $db;
        $this->lang = $lang;
    }

    public static function lang(string $code): string {
        return strpos(strtolower($code), 'uk') === 0 || strpos(strtolower($code), 'ru') === 0 ? 'uk' : 'en';
    }

    public function sections(): array {
        $out = [];

        foreach (self::BUILTIN_SECTIONS as $k => $t) {
            $out[$k] = $t[$this->lang] ?? $t['en'];
        }

        return $out;
    }

    /**
     * FREE build: the showcase cards (no bodies) so the grayed "Prompts" tab shows the feature set.
     * They are inert - the AI never gets them (mcpList() is empty and Server::promptsLine() is off in free).
     */
    public function all(bool $withDisabled = false): array {
        $out = [];
        $i = 0;

        foreach (self::SHOWCASE as $p) {
            $out[] = ['name' => $p['name'], 'section' => $p['section'], 'title' => $p['title'][$this->lang] ?? $p['title']['en'],
                      'description' => $p['description'][$this->lang] ?? $p['description']['en'], 'body' => '',
                      'enabled' => true, 'custom' => false, 'changed' => false, 'sort' => $i++];
        }

        return $out;
    }

    public function get(string $name, bool $withDisabled = false): ?array {
        return null;
    }

    public function render(string $name, array $args = []): string {
        return '';
    }

    public function text(string $name): ?string {
        return null;
    }

    public function mcpList(): array {
        return [];
    }

    public function mcpGet(string $name, array $args): array {
        return ['description' => '', 'messages' => []];
    }

    public function save(string $name, string $title, string $description, string $body, bool $enabled, string $section): string {
        return '';
    }

    public function reset(string $name): void {
    }

    public function delete(string $name): void {
    }

    public function addSection(string $title): string {
        return '';
    }

    public function deleteSection(string $key): void {
    }

    public static function defaults(): array {
        return [];
    }
}
