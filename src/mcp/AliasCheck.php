<?php
namespace OcMcp;

/**
 * Asks the store's own short address from the server side: does /mcp really reach the module, or does the hosting answer
 * 404 (the .htaccess rewrite rules are off) or a store page (the alias is missing or taken over by an SEO extension)?
 */
final class AliasCheck {
    /** @return array{status:int|null, body:string, error:string} */
    public static function probe(string $url, int $timeout = 6): array {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'OpenCart-MCP self-check']);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = (string)curl_error($ch);
            curl_close($ch);

            if ($body === false || $status === 0) {
                return ['status' => null, 'body' => '', 'error' => $err !== '' ? $err : 'no answer'];
            }

            return ['status' => $status, 'body' => substr((string)$body, 0, 2000), 'error' => ''];
        }

        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 3,
            'header' => "Accept: application/json\r\nUser-Agent: OpenCart-MCP self-check\r\n"]]);
        $body = @file_get_contents($url, false, $context);
        $status = null;

        foreach (isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [] as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string)$h, $m)) {
                $status = (int)$m[1]; // the last status wins after redirects
            }
        }

        if ($body === false && $status === null) {
            $last = error_get_last();

            return ['status' => null, 'body' => '', 'error' => is_array($last) ? (string)$last['message'] : 'no answer'];
        }

        return ['status' => $status, 'body' => substr((string)$body, 0, 2000), 'error' => ''];
    }

    /** ok (the module answered, with or without a token) | dead (404 from the web server) | wrong (a store page) | unreachable */
    public static function verdict(array $r): string {
        if ($r['status'] === null) {
            return 'unreachable';
        }

        $body = ltrim((string)$r['body']);

        if (strpos($body, '"jsonrpc"') !== false || (strpos($body, '{') === 0 && strpos($body, '"error"') !== false)) {
            return 'ok';
        }

        return (int)$r['status'] === 404 ? 'dead' : 'wrong';
    }
}
