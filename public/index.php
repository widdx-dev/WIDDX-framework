<?php

declare(strict_types=1);

// Single entry point — all requests arrive here via .htaccess.

define('WIDDX_BASE_PATH', dirname(__DIR__));

$autoload = WIDDX_BASE_PATH . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}

// Fallback manual autoloader when composer is not used yet (quick testing)
if (!class_exists('Widdx\App')) {
    spl_autoload_register(function ($class) {
        $prefixes = [
            'Widdx\\' => WIDDX_BASE_PATH . '/core/',
            'App\\' => WIDDX_BASE_PATH . '/app/',
            'Psr\\' => WIDDX_BASE_PATH . '/polyfill/', // offline stand-in for psr/container
        ];
        foreach ($prefixes as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        }
    });
    require WIDDX_BASE_PATH . '/core/helpers.php';
}

Widdx\App::run(WIDDX_BASE_PATH);
