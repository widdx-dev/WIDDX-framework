<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Contracts\CacheInterface;
use Widdx\Contracts\LoggerInterface;
use Widdx\Contracts\MailInterface;
use Widdx\Contracts\QueueInterface;
use Widdx\Contracts\StorageInterface;

/**
 * Application core: boot + friendly error handling + dispatch.
 */
class App
{
    protected static string $basePath = '';
    protected static array $config = [];

    public static function basePath(string $path = ''): string
    {
        if ($path !== '') {
            static::$basePath = rtrim($path, '/');
        }
        return static::$basePath;
    }

    /** Load environment variables from .env file (if exists) into $_ENV */
    public static function loadEnv(string $basePath): void
    {
        $file = $basePath . '/.env';
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim(trim($value));
            // Remove surrounding quotes
            if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            if ($key === '' || array_key_exists($key, $_ENV)) {
                continue;
            }
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    /** Load config files from config/*.php */
    public static function loadConfig(string $configPath): void
    {
        static::$config = [];
        foreach (glob($configPath . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $data = require $file;
            static::$config[$key] = is_array($data) ? $data : [];
        }
    }

    public static function getConfig(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return static::$config;
        }
        // Support dot notation: database.default
        $segments = explode('.', $key);
        $value = static::$config;
        foreach ($segments as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return $default;
            }
            $value = $value[$seg];
        }
        return $value;
    }

    /** Full boot from public/index.php */
    public static function run(string $basePath): void
    {
        static::basePath($basePath);
        static::loadEnv($basePath);
        static::loadConfig($basePath . '/config');
        // Register handlers first so any failure below gets a friendly page.
        static::registerErrorHandlers();
        Session::start();
        static::registerCoreServices();

        View::setBasePath($basePath . '/views');
        View::setCorePath($basePath . '/core/views'); // built-in zero-code CRUD templates

        $request = Request::capture();
        Container::getInstance()->instance(Request::class, $request);
        Container::getInstance()->instance('request', $request);

        // Load routes (registered via global get/post/... functions)
        $routesFile = $basePath . '/routes/web.php';
        if (is_file($routesFile)) {
            require $routesFile;
        }

        try {
            $response = Router::getInstance()->dispatch($request);
        } catch (\Throwable $e) {
            $response = static::renderException($e);
        }

        $response->send();

        // Fire queued events AFTER the response reached the client —
        // listeners must never delay or corrupt the output.
        Event::flush();
    }

    /** Register core services in the container */
    protected static function registerCoreServices(): void
    {
        $container = Container::getInstance();

        // Cache
        $cacheDriver = (string) static::getConfig('cache.default', 'file');
        $cacheClass = $cacheDriver === 'array' ? ArrayCache::class : FileCache::class;
        $container->singleton(CacheInterface::class, $cacheClass);
        $container->singleton(FileCache::class, FileCache::class);
        $container->singleton(ArrayCache::class, ArrayCache::class);

        // Logger
        $container->singleton(LoggerInterface::class, FileLogger::class);
        $container->singleton(FileLogger::class, FileLogger::class);

        // Storage
        $container->singleton(StorageInterface::class, LocalStorage::class);
        $container->singleton(LocalStorage::class, LocalStorage::class);

        // Session / Auth / Event are static facades — no container bindings needed.

        // Queue
        $queueDriver = (string) static::getConfig('queue.default', 'sync');
        $queueClass = $queueDriver === 'database' ? DatabaseQueue::class : SyncQueue::class;
        $container->singleton(QueueInterface::class, $queueClass);
        $container->singleton(Queue::class, Queue::class);

        // Mail
        $mailDriver = (string) static::getConfig('mail.default', 'log');
        $mailClass = $mailDriver === 'smtp' ? SmtpMail::class : LogMail::class;
        $container->singleton(MailInterface::class, $mailClass);
        $container->singleton(Mail::class, Mail::class);
    }

    /** Friendly error handlers that point to the problem location */
    public static function registerErrorHandlers(): void
    {
        set_exception_handler(function (\Throwable $e) {
            static::renderException($e)->send();
        });

        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    /** Convert any exception to a clear error page */
    public static function renderException(\Throwable $e): Response
    {
        // Validation errors → redirect back with errors + old input (zero controller code)
        if ($e instanceof Exceptions\ValidationException && PHP_SAPI !== 'cli') {
            Session::flash('_errors', $e->errors);
            try {
                Session::flash('_old', request()->except(['password', 'password_confirmation', 'csrf_token']));
            } catch (\Throwable) {
                // Ignore — redirect without old input
            }
            $back = $_SERVER['HTTP_REFERER'] ?? '/';
            return Response::redirect($back);
        }

        $debug = (bool) static::getConfig('app.debug', true);

        $status = 500;
        if ($e instanceof Exceptions\RouteNotFoundException || $e instanceof Exceptions\NotFoundException) {
            $status = 404;
        } elseif ($e instanceof Exceptions\HttpException) {
            $status = $e->status;
        }

        // CLI mode: print simple text (return empty Response to avoid double output)
        if (PHP_SAPI === 'cli') {
            $msg = get_class($e) . ': ' . $e->getMessage() . "\n  at {$e->getFile()}:{$e->getLine()}\n";
            fwrite(STDERR, $msg);
            return Response::make('', $status);
        }

        // Try custom error template if exists
        $viewFile = static::$basePath . "/views/errors/{$status}.php";
        if (is_file($viewFile) && !$debug) {
            try {
                $html = View::make("errors.{$status}", ['exception' => $e]);
                return Response::make($html, $status);
            } catch (\Throwable) {
                // Fall through to default page
            }
        }

        $titles = [
            404 => 'Page Not Found (404)',
            405 => 'Method Not Allowed (405)',
            419 => 'CSRF Token Mismatch (419)',
            422 => 'Validation Failed (422)',
        ];
        $title = $titles[$status] ?? "Something Went Wrong ({$status})";
        $hint = static::friendlyHint($e);

        $trace = '';
        if ($debug) {
            $trace = '<h2>Technical Details (Debug Mode)</h2>'
                . '<p><strong>Type:</strong> ' . htmlspecialchars(get_class($e)) . '</p>'
                . '<p><strong>Message:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>'
                . '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ' : line ' . $e->getLine() . '</p>'
                . '<pre dir="ltr" style="text-align:left;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:8px;overflow:auto">'
                . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} - Widdx</title>
<style>body{font-family:system-ui,Tahoma,sans-serif;background:#f8fafc;margin:0;padding:40px;color:#1e293b}.card{max-width:720px;margin:auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 4px 24px rgba(0,0,0,.08)}h1{margin-top:0}.hint{background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:12px 16px}a{color:#2563eb}</style>
</head>
<body><div class="card">
<h1>{$title}</h1>
<p>{$hint}</p>
<p><a href="/">← Back to Home</a></p>
{$trace}
</div></body></html>
HTML;
        return Response::make($html, $status);
    }

    /** Friendly Arabic hint based on exception type */
    protected static function friendlyHint(\Throwable $e): string
    {
        if ($e instanceof Exceptions\RouteNotFoundException) {
            return 'This URL is not registered. Open <code>routes/web.php</code> and add the required route.';
        }
        if ($e instanceof Exceptions\ViewNotFoundException) {
            return 'The view template does not exist. Check the name passed to <code>view()</code> and verify the file exists in <code>views/</code>.';
        }
        if ($e instanceof Exceptions\BindingException) {
            return 'Automatic dependency injection failed. Check the details below — likely a parameter name that does not match <code>{param}</code> in the route, or a missing class.';
        }
        if ($e instanceof Exceptions\DatabaseException) {
            return 'Database problem. Check <code>config/database.php</code> and verify the required table exists.';
        }
        if ($e instanceof Exceptions\NotFoundException) {
            return 'The requested item does not exist. Check the ID passed in the URL.';
        }
        if ($e instanceof Exceptions\MethodNotAllowedException) {
            return 'The URL exists but the HTTP method is wrong. Check the form <code>method</code> (use <code>_method</code> for PUT/DELETE) or the API request method.';
        }
        if ($e instanceof Exceptions\ValidationException) {
            return 'Some inputs are invalid. Check the validation errors below.';
        }
        return 'An unexpected error occurred. Check the details below or the error log.';
    }
}
