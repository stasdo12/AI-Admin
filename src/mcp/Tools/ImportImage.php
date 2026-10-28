<?php
namespace OcMcp\Tools;

use OcMcp\Context;

/**
 * FREE / demo build: the write tool `import_image` is removed (read-only distribution).
 * Only the static src() helper is kept - ListImages uses it to build an image's public URL.
 * The full download / re-encode / store logic lives in the paid build only.
 * This is a plain helper class on purpose: it is NOT registered as a tool.
 */
final class ImportImage {
    public static function src(Context $c, string $path): string {
        $base = rtrim((string)(parse_url($c->storeUrl, PHP_URL_PATH) ?: ''), '/');

        return $base . '/image/' . $path;
    }
}
