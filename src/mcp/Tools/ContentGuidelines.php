<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\ImageStore;
use OcMcp\Tool;
use OcMcp\ToolResult;

/** What the store enforces on texts and images, plus the owner's writing guide for one kind of page. */
final class ContentGuidelines extends Tool {
    const GUIDE = ['product' => 'product', 'category' => 'category', 'article' => 'blog', 'manufacturer' => 'manufacturer', 'information' => 'blog'];

    public function name(): string {
        return 'content_guidelines';
    }

    public function describe(): array {
        return [
            'title'       => 'Content rules of this store',
            'description' => 'Call before writing store texts: the rules the store enforces on HTML, emoji, links and images (set by the owner), the fields to fill / ask about / skip for the kind of page, and the owner\'s writing guide.',
            'inputSchema' => self::schema(['type' => self::prop('string', 'Kind of page.', ['enum' => array_keys(self::GUIDE)])]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $type = $this->enum($a, 'type', array_keys(self::GUIDE), 'product');
        $s = $c->content()->settings();
        $rules = [
            'ask_first' => $s['ask_first'] ? 'show every change as a preview (before -> after) and change the store only after the user says yes: a write call answers with its preview and an approval code, after the yes repeat it with approval=<that code>; a task of many pages: series_total in the first preview, one yes for all of them' : 'the owner allows changes without asking first',
            'bulk' => ($s['bulk_preview'] ? 'more than one product at once: preview first, always' : 'bulk changes run without a preview') . '; many records are one call (bulk_update_products filter / rows, items / product_ids / order_ids / review_ids), never a loop of single calls',
            'new_pages' => $s['drafts'] ? 'new pages are created disabled (draft); enable them after the user agrees' : 'new pages are created enabled',
            'seo_urls' => 'made from the name (Ukrainian transliterated by the official system), unique' . ($s['url_confirm'] ? '; an existing URL changes only after the user explicitly agrees (overwrite=true)' : ''),
            'html' => $s['html'] ? 'HTML allowed in descriptions: h2-h4, p, lists, strong/em, a, img, table, blockquote' . ($s['css'] ? ', inline styles and classes' : ', no inline styles') : 'plain text only, no HTML',
            'h1' => 'not inside the description text (an h1 there becomes h2); the page heading is the field meta_h1 where the store has it: fill it',
            'emoji' => $s['emoji'] ? 'allowed' : 'not allowed anywhere (removed)',
            'video' => $s['video'] ? 'YouTube / Vimeo embeds (iframe src https://www.youtube.com/embed/ID) allowed, made responsive' : 'no video embeds',
            'links' => 'links to this store become relative and point to the page in the language of the text; external links get rel="noopener' . ($s['nofollow'] ? ' nofollow' : '') . '"',
            'images_in_text' => 'centered, never stretched; every image gets alt and title (write meaningful ones yourself, otherwise the page name is used)',
            'delete_images' => $s['img_delete'] ? 'allowed with delete_image, only photos no page uses (they go to the trash and roll back)' : 'not allowed: the owner has not enabled deleting photos',
            'images' => 'one page: import_image with entity + id (attach puts them on it). Many pages: import_image without entity (up to 20 links into catalog/incoming), then set_images items for up to 100 pages in one call - the photos move to each page\'s folder (catalog/products/<brand>/<product>/, catalog/category/<name>/ ...); at most 2000 px wide and 2 MB',
            'never' => 'scripts, iframes other than video, forms, event handlers, javascript: links are always removed',
        ];
        $guide = $c->prompts()->text(self::GUIDE[$type]);
        $out = ['type' => $type, 'rules' => $rules];
        $fields = \OcMcp\FieldPlan::fields($c, $type);

        if ($fields) {
            $out['fields'] = $fields;
            $out['fields_rule'] = 'Before creating or filling pages: fill every "fill" field yourself. For "ask" fields the user has not decided, ask ONCE, in one short message listing them with your suggestion '
                . '(e.g. "SKU: as the model or empty? Photos: from the brand\'s site? Status: draft or enabled? Price and stock: your values?"); leave "skip" fields unless the user gives a value. '
                . 'Price, stock, SKU and barcodes only from the user (values, a list, a rule, or a task such as "find competitors" - then show the sources in the preview); never on your own. The save answer lists what stayed empty.';
        }

        $out['writing_guide'] = $guide;

        return ToolResult::data($out);
    }
}
