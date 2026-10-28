<?php
namespace OcMcp;

/**
 * Framework-agnostic snapshot of the incoming HTTP request.
 */
final class HttpRequest {
    /** @var string */
    public $method = 'GET';
    /** @var array<string,string> lower-case header name => value */
    public $headers = [];
    /** @var string raw body */
    public $body = '';
    /** @var array */
    public $query = [];
    /** @var string */
    public $ip = '';
    /** @var string */
    public $uri = '';
    /** @var string SEO keyword the request came in through (OpenCart `_route_`), if any */
    public $routeKeyword = '';

    public static function fromGlobals(): self {
        $r = new self();
        $r->method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $r->uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $r->ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $r->query = $_GET;
        $r->body = (string)file_get_contents('php://input');
        $r->headers = self::collectHeaders();
        $r->routeKeyword = isset($_GET['_route_']) ? (string)$_GET['_route_'] : '';

        return $r;
    }

    public function header(string $name): ?string {
        $key = strtolower($name);

        return isset($this->headers[$key]) ? (string)$this->headers[$key] : null;
    }

    /**
     * Collect headers, taking care of the many ways Apache/FastCGI hide the Authorization header.
     */
    private static function collectHeaders(): array {
        $h = [];

        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $h[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $h['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
        }

        if (empty($h['authorization']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $h['authorization'] = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (empty($h['authorization']) && function_exists('apache_request_headers')) {
            $all = @apache_request_headers();

            if (is_array($all)) {
                foreach ($all as $k => $v) {
                    if (strtolower((string)$k) === 'authorization') {
                        $h['authorization'] = (string)$v;
                    }
                }
            }
        }

        if (empty($h['authorization']) && function_exists('getallheaders')) {
            $all = @getallheaders();

            if (is_array($all)) {
                foreach ($all as $k => $v) {
                    if (strtolower((string)$k) === 'authorization') {
                        $h['authorization'] = (string)$v;
                    }
                }
            }
        }

        return $h;
    }
}
