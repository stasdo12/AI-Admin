<?php
namespace OcMcp;

/**
 * Base class for tools. Subclasses declare a name, a JSON schema and an access level.
 */
abstract class Tool {
    const LEVEL_READ      = 'read';
    const LEVEL_WRITE     = 'write';

    abstract public function name(): string;

    /** Tool definition without `name` (title, description, inputSchema, annotations). */
    abstract public function describe(): array;

    public function level(): string {
        return self::LEVEL_READ;
    }

    /** Tables the tool reads; if any of them is closed for the AI the tool is hidden. Owner-hidden fields are stripped from its output. */
    public function tables(): array {
        return [];
    }

    abstract public function run(array $args, Context $ctx): ToolResult;

    /** Run a multi-statement write inside a transaction; an error result or exception rolls everything back. */
    protected function atomic(Context $c, callable $fn): ToolResult {
        $c->db->begin();

        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $c->db->rollback();
            throw $e;
        }

        if ($result->isError) {
            $c->db->rollback();
        } else {
            $c->db->commit();
        }

        return $result;
    }

    /**
     * Tools that return records: the key of the record id. Their callers may ask for only some fields ("fields"), which
     * makes answers on big catalogs several times shorter. null = no field selection.
     */
    public function fieldsKey(): ?string {
        return null;
    }

    /** Write tools that can show what they would change without changing anything (preview=true). */
    public function supportsPreview(): bool {
        return false;
    }

    protected function isPreview(array $a): bool {
        return $this->supportsPreview() && (bool)$this->bool($a, 'preview', false);
    }

    /**
     * Preview answer: every change as target / field / before / after, nothing written, nothing journaled.
     * @param array $changes list of ['target' => 'product 40', 'field' => 'price', 'before' => .., 'after' => ..]
     */
    protected static function previewResult(array $changes, array $extra = []): ToolResult {
        $n = count($changes);

        return ToolResult::data(['preview' => true, 'changes' => array_values($changes), 'changes_total' => $n] + $extra,
            "PREVIEW - nothing was changed. $n change(s) below: show them to the user (as a short before -> after table) and repeat the same call without preview after they agree.");
    }

    /**
     * The owner's "preview before a bulk change" holds this call back: the setting is on, more than $from items change and
     * the call has no confirm=true. With "ask before changes" the server's own approval is the confirmation, so it never holds.
     */
    protected function bulkHeld(array $a, Context $c, int $count, int $from = 1): bool {
        $s = $c->content()->settings();

        return $s['bulk_preview'] && !$s['ask_first'] && $count > $from && !$this->bool($a, 'confirm', false);
    }

    /**
     * A preview (at most 100 rows shown). When it holds a write back instead of answering preview=true it says so first, so
     * the AI shows it and repeats the call with confirm=true - the server recognises it by its first words.
     */
    protected function heldPreview(array $a, array $changes, array $extra, string $what): ToolResult {
        $r = self::previewResult(array_slice($changes, 0, 100), $extra + (count($changes) > 100 ? ['changes_shown' => 100] : []));

        return $this->isPreview($a) ? $r : $r->prefixed('NOTHING CHANGED YET: ' . $what . ' Show it to the user; after they agree call again with the same arguments and confirm=true.');
    }

    /** Seconds a call of many items may write before it hands the rest back. */
    const ITEMS_BUDGET = 20.0;

    /**
     * Many items of one call, each checked and written like a single call, all in one journal batch. Every item is first run as
     * a preview: a refusal or a wrong field anywhere in the list stops the call before the first write (all or nothing; log of
     * 2026-10-16: a category was saved, the manufacturer after it refused, the page after that never ran). A write that fails
     * anyway (the store refused it) stops the run there: the answer lists what was written before it (one rollback undoes it).
     * @param callable $one   function(array $item, int $i, bool $dry): ToolResult - one item; $dry = check it as a preview
     * @param callable $label function(array $item, int $i): string - "items[3] (product 42)", for previews and errors
     * @param callable $keep  function(ToolResult $r, array $item, int $i): array - what the answer lists for a written item
     * @return ToolResult|array{changes: array, done: array} a finished answer (stopped / refused) or what the items gave
     */
    protected function eachItem(array $items, array $keys, bool $preview, string $noun, Context $c, callable $one, callable $label, callable $keep) {
        $changes = [];
        $done = [];

        if (!$preview && count($items) > 1) {
            foreach ($items as $i => $it) {
                if (!is_array($it)) {
                    return ToolResult::error("items[$i] must be an object. Nothing was changed.");
                }

                unset($it['items'], $it['approval'], $it['idempotency_key']);

                try {
                    self::onlyKeys($it, $keys, "items[$i]");
                    $r = $one($it, $i, true);
                } catch (ToolException $e) {
                    $r = ToolResult::error($e->getMessage());
                }

                if ($r->isError) {
                    $text = rtrim(preg_replace('/\s*Nothing (was|has been) changed\.?\s*$/i', '', $r->firstText()));

                    return ToolResult::error($label($it, $i) . ': ' . $text . ' Nothing was changed: every item is checked before the first one is written; fix this one and send the whole list again.');
                }
            }
        }

        $start = microtime(true);

        foreach ($items as $i => $it) {
            // a shared host stops a request after 30-60 s: past the budget the written items are kept and the rest is handed back
            if ($done && !$preview && microtime(true) - $start > static::ITEMS_BUDGET) {
                return ToolResult::data(['done' => $done, 'continue_from_item' => $i, 'change_batch' => $c->journal()->batch()], count($done) . " $noun(s) saved (one rollback undoes them); the store needs a pause."
                    . ' Call again with the remaining items (from items[' . $i . ']) - nothing after it was written.');
            }

            if (!is_array($it)) {
                return ToolResult::error("items[$i] must be an object.");
            }

            unset($it['items'], $it['approval'], $it['idempotency_key']);
            $name = $label($it, $i);

            try {
                self::onlyKeys($it, $keys, "items[$i]");
                $r = $one($it, $i, false);
            } catch (ToolException $e) {
                $r = ToolResult::error($e->getMessage());
            }

            if ($r->isError) {
                $text = $name . ': ' . $r->firstText();

                return $done ? ToolResult::data(['done' => $done, 'stopped_at' => $i, 'error' => $text, 'change_batch' => $c->journal()->batch()], 'Stopped at ' . $text . ' The ' . count($done) . " $noun(s) before it are saved (one rollback undoes them).")
                    : ToolResult::error($text);
            }

            if ($preview) {
                foreach ((array)($r->structured['changes'] ?? []) as $ch) {
                    $changes[] = ['target' => $name . (isset($ch['target']) ? ' ' . $ch['target'] : '')] + $ch;
                }
            } else {
                $done[] = $keep($r, $it, $i);
            }
        }

        return ['changes' => $changes, 'done' => $done];
    }

    /** Creating calls (a new product, a mail draft ...): an identical repeat within 10 minutes returns the first result. */
    public function autoIdempotent(array $args): bool {
        return false;
    }

    public function definition(): array {
        $d = array_merge(['name' => $this->name()], $this->describe());

        if ($this->supportsPreview() && isset($d['inputSchema']['properties']) && is_array($d['inputSchema']['properties'])) {
            // the same four properties on many tools: explained once in the server instructions, not in every tool (tokens in every chat)
            $d['inputSchema']['properties']['preview'] = ['type' => 'boolean'];
            $d['inputSchema']['properties']['approval'] = ['type' => 'string'];

            if (in_array($this->name(), \OcMcp\Series::TOOLS, true)) {
                $d['inputSchema']['properties']['series_total'] = ['type' => 'integer'];
            }
        }

        if ($this->level() !== self::LEVEL_READ && isset($d['inputSchema']['properties']) && is_array($d['inputSchema']['properties'])) {
            $d['inputSchema']['properties']['idempotency_key'] = ['type' => 'string'];
        }

        if ($this->fieldsKey() !== null && isset($d['inputSchema']['properties']) && is_array($d['inputSchema']['properties'])) {
            $d['inputSchema']['properties']['fields'] = ['type' => 'array', 'items' => ['type' => 'string']];
        }

        // no outputSchema in tools/list: it cost ~11 KB (about 3 000 tokens) in every chat and the store assistants do not use
        // it; answers still carry structuredContent, and OutputSchemas stays the contract the stand checks hold them to

        return $d;
    }

    // ------------------------------------------------------------------ schema helpers

    protected static function schema(array $properties, array $required = []): array {
        $schema = [
            'type'       => 'object',
            'properties' => $properties ? $properties : new \stdClass(),
        ];

        if ($required) {
            $schema['required'] = array_values($required);
        }

        return $schema;
    }

    protected static function prop(string $type, string $description, array $extra = []): array {
        return array_merge(['type' => $type, 'description' => $description], $extra);
    }

    protected static function annotations(bool $readOnly, bool $destructive = false, bool $idempotent = false): array {
        return [
            'readOnlyHint'    => $readOnly,
            'destructiveHint' => $destructive,
            'idempotentHint'  => $idempotent,
            'openWorldHint'   => false,
        ];
    }

    // ------------------------------------------------------------------ argument helpers

    protected function has(array $a, string $key): bool {
        return array_key_exists($key, $a) && $a[$key] !== null && $a[$key] !== '';
    }

    protected function int(array $a, string $key, ?int $default = null, ?int $min = null, ?int $max = null): ?int {
        if (!$this->has($a, $key)) {
            return $default;
        }

        if (!is_numeric($a[$key])) {
            throw new ToolException("Argument '$key' must be an integer.");
        }

        $v = (int)$a[$key];

        if ($min !== null && $v < $min) {
            $v = $min;
        }

        if ($max !== null && $v > $max) {
            $v = $max;
        }

        return $v;
    }

    protected function num(array $a, string $key, ?float $default = null): ?float {
        if (!$this->has($a, $key)) {
            return $default;
        }

        if (!is_numeric($a[$key])) {
            throw new ToolException("Argument '$key' must be a number.");
        }

        return (float)$a[$key];
    }

    protected function str(array $a, string $key, ?string $default = null): ?string {
        if (!$this->has($a, $key)) {
            return $default;
        }

        if (is_array($a[$key])) {
            throw new ToolException("Argument '$key' must be a string.");
        }

        return trim((string)$a[$key]);
    }

    protected function bool(array $a, string $key, ?bool $default = null): ?bool {
        if (!array_key_exists($key, $a) || $a[$key] === null || $a[$key] === '') {
            return $default;
        }

        $v = $a[$key];

        if (is_bool($v)) {
            return $v;
        }

        if (is_string($v)) {
            $l = strtolower($v);

            if (in_array($l, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($l, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        if (is_numeric($v)) {
            return (bool)$v;
        }

        throw new ToolException("Argument '$key' must be a boolean.");
    }

    protected function enum(array $a, string $key, array $allowed, string $default): string {
        $v = $this->str($a, $key, $default);

        if (!in_array($v, $allowed, true)) {
            throw new ToolException("Argument '$key' must be one of: " . implode(', ', $allowed) . '.');
        }

        return $v;
    }

    protected function date(array $a, string $key, ?string $default = null): ?string {
        $v = $this->str($a, $key, $default);

        if ($v === null) {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) {
            throw new ToolException("Argument '$key' must be a date in YYYY-MM-DD format.");
        }

        return $v;
    }

    /**
     * Refuse keys this object does not take: an ignored key looks like success ("0 changes") or, in a filter, quietly
     * widens what is changed. Empty values (false, null, '', [], 0) are let through: a stray "preview": false is harmless.
     */
    public static function onlyKeys(array $obj, array $allowed, string $where): void {
        $bad = [];

        foreach ($obj as $k => $v) {
            if (!in_array((string)$k, $allowed, true) && !($v === null || $v === false || $v === '' || $v === [] || $v === 0 || $v === '0')) {
                $bad[] = (string)$k;
            }
        }

        if ($bad) {
            throw new ToolException("$where: " . implode(', ', $bad) . (count($bad) > 1 ? ' are' : ' is') . ' not known here and would be ignored. Allowed: ' . implode(', ', $allowed) . '. Nothing was changed.');
        }
    }

    /** Property names of one object in this tool's own schema (top level when $path is empty, e.g. 'filter' or 'set'). */
    protected function schemaKeys(string $path = ''): array {
        $p = $this->definition()['inputSchema']['properties'] ?? [];

        foreach ($path === '' ? [] : explode('.', $path) as $seg) {
            $p = $p[$seg]['properties'] ?? ($p[$seg]['items']['properties'] ?? []);
        }

        return array_keys((array)$p);
    }

    protected function need(array $a, string $key): void {
        if (!$this->has($a, $key)) {
            throw new ToolException("Argument '$key' is required.");
        }
    }

    protected function limit(array $a, int $default = 25, int $max = 200): int {
        return (int)$this->int($a, 'limit', $default, 1, $max);
    }

    protected function offset(array $a): int {
        return (int)$this->int($a, 'offset', 0, 0, 1000000);
    }

    /** OC4 stores payment/shipping method as JSON {name, code}; OC3 as plain name. Normalize to name. */
    protected static function methodName($value): string {
        $value = (string)$value;

        if ($value !== '' && $value[0] === '{') {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return (string)($decoded['name'] ?? $decoded['code'] ?? $value);
            }
        }

        return $value;
    }

    protected static function plainText(?string $html, int $max = 1500): string {
        $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
            return mb_substr($text, 0, $max, 'UTF-8') . '...';
        }

        if (!function_exists('mb_strlen') && strlen($text) > $max) {
            return substr($text, 0, $max) . '...';
        }

        return $text;
    }
}
