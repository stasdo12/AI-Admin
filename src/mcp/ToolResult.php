<?php
namespace OcMcp;

/**
 * Result of a tool call. Always carries a text block (for every client) and, for data, structuredContent.
 */
final class ToolResult {
    /** @var array */
    public $content = [];
    /** @var mixed|null */
    public $structured = null;
    /** @var bool */
    public $isError = false;
    /** @var string|null */
    private $summary = null;

    public static function text(string $text): self {
        $r = new self();
        $r->content[] = ['type' => 'text', 'text' => $text];

        return $r;
    }

    /**
     * Data result: structuredContent + the same JSON as text (older clients only read text).
     * @param array $data associative array (JSON object)
     */
    public static function data(array $data, ?string $summary = null): self {
        $r = new self();
        // compact JSON: the text copy is read by the model, indentation would only cost tokens
        $json = (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $r->content[] = ['type' => 'text', 'text' => ($summary !== null ? $summary . "\n\n" : '') . $json];
        $r->structured = $data;
        $r->summary = $summary;

        return $r;
    }

    /** The same result with a sentence put before its summary line. */
    public function prefixed(string $text): self {
        $r = self::data((array)$this->structured, $text . ($this->summary !== null ? ' ' . $this->summary : ''));
        $r->isError = $this->isError;

        return $r;
    }

    /** The same result with other data (the summary line is kept). */
    public function withData(array $data): self {
        $r = self::data($data, $this->summary);
        $r->isError = $this->isError;

        return $r;
    }

    public static function error(string $message): self {
        $r = self::text($message);
        $r->isError = true;

        return $r;
    }

    public function firstText(): string {
        foreach ($this->content as $c) {
            if (($c['type'] ?? '') === 'text') {
                return (string)$c['text'];
            }
        }

        return '';
    }

    public function toArray(): array {
        $out = ['content' => $this->content];

        if ($this->structured !== null) {
            $out['structuredContent'] = $this->structured;
        }

        if ($this->isError) {
            $out['isError'] = true;
        }

        return $out;
    }
}
