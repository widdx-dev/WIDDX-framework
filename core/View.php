<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Template engine: plain PHP + extract() + include.
 */
class View
{
    protected static string $basePath = '';
    protected static string $corePath = '';

    /** Set views directory (called once at boot) */
    public static function setBasePath(string $path): void
    {
        static::$basePath = rtrim($path, '/');
    }

    /**
     * Set the framework's built-in views directory (core/views).
     * Used by View::core() — the automatic fallback when a project
     * does not ship its own CRUD templates.
     */
    public static function setCorePath(string $path): void
    {
        static::$corePath = rtrim($path, '/');
    }

    /**
     * Render a template: view('users.index', ['users' => $users])
     * Dot means subdirectory: users.index → views/users/index.php
     */
    public static function make(string $view, array $data = []): string
    {
        $file = static::$basePath . '/' . str_replace('.', '/', $view) . '.php';

        if (!is_file($file)) {
            throw new Exceptions\ViewNotFoundException(
                "Template [{$view}] not found. Searched in: {$file}. " .
                "Check the name (dot = subdirectory)."
            );
        }

        // Isolate scope so internal variables don't leak
        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw new Exceptions\ViewException(
                    "Error executing template [{$__file}]: {$e->getMessage()}",
                    previous: $e
                );
            }
            return (string) ob_get_clean();
        };

        return $render($file, $data);
    }

    /**
     * Render one of the framework's built-in templates from core/views.
     * These power zero-code CRUD when the project has no own views.
     */
    public static function core(string $view, array $data = []): string
    {
        $file = static::$corePath . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new Exceptions\ViewNotFoundException(
                "Built-in template [{$view}] not found. Searched in: {$file}."
            );
        }

        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw new Exceptions\ViewException(
                    "Error executing template [{$__file}]: {$e->getMessage()}",
                    previous: $e
                );
            }
            return (string) ob_get_clean();
        };

        return $render($file, $data);
    }

    /** Does the template exist? */
    public static function exists(string $view): bool
    {
        return is_file(static::$basePath . '/' . str_replace('.', '/', $view) . '.php');
    }
}
