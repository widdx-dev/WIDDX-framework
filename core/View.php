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
        $file = static::resolveTemplate(static::$basePath, $view);

        if ($file === null) {
            throw new Exceptions\ViewNotFoundException(
                "Template [{$view}] not found. Searched in: " .
                static::$basePath . '/' . str_replace('.', '/', $view) . '.php. ' .
                "Check the name (dot = subdirectory); templates must live inside the views directory."
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
        $file = static::resolveTemplate(static::$corePath, $view);
        if ($file === null) {
            throw new Exceptions\ViewNotFoundException(
                "Built-in template [{$view}] not found. Searched in: " .
                static::$corePath . '/' . str_replace('.', '/', $view) . '.php.'
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

    /** Does the template exist (inside the views directory)? */
    public static function exists(string $view): bool
    {
        return static::resolveTemplate(static::$basePath, $view) !== null;
    }

    /**
     * Resolve a dot-notation template name to a real file that provably lives
     * inside the configured directory — the same containment rule
     * LocalStorage::fullPath() applies. Dot replacement stops classical
     * "../.." traversal, but a view name can still carry raw "/" or '\' (or a
     * symlink inside the views directory can point outside it); realpath()
     * settles the target for real instead of trusting string munging.
     * Returns null when the file is missing, unreadable, or escaped the base.
     */
    protected static function resolveTemplate(string $base, string $view): ?string
    {
        if ($base === '') {
            return null;
        }
        $baseReal = realpath($base);
        if ($baseReal === false) {
            return null;
        }
        $file = realpath($baseReal . '/' . str_replace('.', '/', $view) . '.php');
        if ($file === false || !is_file($file)
            || !str_starts_with($file, $baseReal . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $file;
    }
}
