<?php
/**
 * OpenCart MCP Server - minimal PSR-4 style autoloader for the OcMcp namespace.
 *
 * No Composer required (shared-hosting friendly). Works on PHP 7.1+ (old shared hostings included).
 */
// The release build encodes the core with ionCube and drops a ".encoded" marker here (this file stays plain PHP).
// Without the free ionCube Loader the encoded files cannot run: say so clearly instead of a blank page or a fatal error.
if (is_file(__DIR__ . '/.encoded') && !extension_loaded('ionCube Loader')) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo "MCP Server: the hosting has no ionCube Loader (PHP " . PHP_VERSION . ").\n"
        . "The module is protected with ionCube and needs the free ionCube Loader for this PHP version: https://www.ioncube.com/loaders.php\n"
        . "Most hostings enable it in the control panel (PHP settings / extensions).\n\n"
        . "MCP Server: на хостингу немає ionCube Loader (PHP " . PHP_VERSION . ").\n"
        . "Модуль захищено ionCube, потрібен безкоштовний ionCube Loader для цієї версії PHP. Зазвичай його вмикають у панелі хостингу (налаштування PHP / розширення).\n";
    exit;
}

// PHP 7.1 / 7.2 (still common on old shared hostings) lack these two; the core uses them in a couple of places.
if (!function_exists('array_key_first')) {
    function array_key_first(array $array) {
        foreach ($array as $key => $_) {
            return $key;
        }

        return null;
    }
}

if (!function_exists('array_key_last')) {
    function array_key_last(array $array) {
        return $array ? key(array_slice($array, -1, 1, true)) : null;
    }
}

spl_autoload_register(function ($class) {
    $prefix = 'OcMcp\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
