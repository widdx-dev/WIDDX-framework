<?php

/**
 * Router script for PHP's built-in development server.
 * Used by: php widdx serve  /  php -S host:port -t public/ public/router.php
 *
 * NOT needed in production (Apache/nginx route everything to index.php).
 */
declare(strict_types=1);

$uri = urldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));

// If the URI maps to a real file inside public/, let the dev server handle it
// (serves CSS/JS/images; executes .php files like install.php).
// Anything suspicious (backslashes, NUL, drive letters, absolute paths that
// escaped the docroot) is handed to the framework, which re-validates it.
$safe = $uri !== '/'
    && !str_contains($uri, "\0")
    && !str_contains($uri, '\\')
    && !preg_match('#^[a-zA-Z]:#', $uri)
    && str_starts_with($uri, '/');
$publicFile = __DIR__ . $uri;
if ($safe && is_file($publicFile)) {
    // urldecode can revive %2e%2e → '..' — verify the resolved path stayed
    // inside the docroot before letting the dev server touch it.
    $real = realpath($publicFile);
    $realDir = realpath(__DIR__);
    if ($real !== false && $realDir !== false && str_starts_with($real, $realDir . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

// Everything else → framework front controller.
require __DIR__ . '/index.php';
