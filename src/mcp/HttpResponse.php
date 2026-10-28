<?php
namespace OcMcp;

/**
 * Framework-agnostic HTTP response. We bypass OpenCart's Response object on purpose:
 * it only emits headers when there is a body, which breaks `202 Accepted` with an empty body.
 */
final class HttpResponse {
    /** @var int */
    public $status = 200;
    /** @var array<string,string> */
    public $headers = [];
    /** @var string */
    public $body = '';

    public static function json(int $status, $data): self {
        $r = new self();
        $r->status = $status;
        $r->headers['Content-Type'] = 'application/json; charset=utf-8';
        $r->body = (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return $r;
    }

    public static function noContent(int $status = 202): self {
        $r = new self();
        $r->status = $status;

        return $r;
    }

    public function withHeader(string $name, string $value): self {
        $this->headers[$name] = $value;

        return $this;
    }

    public function send(): void {
        // drop stray output (PHP warnings/notices printed by drivers on hosts with display_errors=On) so it cannot corrupt JSON or headers
        while (ob_get_level() > 0 && @ob_end_clean()) {
        }

        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($this->body !== '') {
            echo $this->body;
        }
    }
}
