<?php

declare(strict_types=1);

// Global helper functions — the heart of Widdx philosophy: minimal code.

use Widdx\App;
use Widdx\ArrayCache;
use Widdx\Auth;
use Widdx\Container;
use Widdx\Database;
use Widdx\DatabaseQueue;
use Widdx\Exceptions\BindingException;
use Widdx\FileCache;
use Widdx\FileLogger;
use Widdx\QueryBuilder;
use Widdx\Lang;
use Widdx\LogMail;
use Widdx\LocalStorage;
use Widdx\Mail;
use Widdx\Queue;
use Widdx\Request;
use Widdx\Response;
use Widdx\Router;
use Widdx\Route;
use Widdx\Session;
use Widdx\SmtpMail;
use Widdx\SyncQueue;
use Widdx\View;
use Widdx\Event;
use Widdx\Contracts\CacheInterface;
use Widdx\Contracts\LoggerInterface;
use Widdx\Contracts\MailInterface;
use Widdx\Contracts\QueueInterface;
use Widdx\Contracts\StorageInterface;

if (!function_exists('view')) {
    /** Render a template: view('users.index', ['users' => $users]) */
    function view(string $name, array $data = []): string
    {
        return View::make($name, $data);
    }
}

if (!function_exists('redirect')) {
    /** Redirect: return redirect('/users'); */
    function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }
}

if (!function_exists('request')) {
    /**
     * request() → current Request object
     * request('name') → input value
     * request('name', 'default') → with default
     */
    function request(?string $key = null, mixed $default = null): mixed
    {
        $container = Container::getInstance();
        $req = $container->has(Request::class)
            ? $container->get(Request::class)
            : Request::capture();

        if ($key === null) {
            return $req;
        }
        return $req->input($key, $default);
    }
}

if (!function_exists('db')) {
    /**
     * db() → Database connection
     * db('users') → QueryBuilder for users table
     */
    function db(?string $table = null): Database|QueryBuilder
    {
        $conn = Database::connection();
        if ($table === null) {
            return $conn;
        }
        return $conn->table($table);
    }
}

if (!function_exists('config')) {
    /** config('app.name') or config('database.driver') */
    function config(?string $key = null, mixed $default = null): mixed
    {
        return App::getConfig($key, $default);
    }
}

if (!function_exists('env')) {
    /**
     * Read environment variable with default: env('DB_HOST', '127.0.0.1')
     * Converts common string values (true/false/null) to their types.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        if (!is_string($value)) {
            return $value;
        }
        $value = trim($value);
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('response')) {
    function response(mixed $content = '', int $status = 200, array $headers = []): Response
    {
        return Response::make($content, $status, $headers);
    }
}

if (!function_exists('abort')) {
    /** abort(404) or abort(403, 'Forbidden') */
    function abort(int $code, string $message = ''): never
    {
        throw new Widdx\Exceptions\HttpException($message ?: "HTTP Error ({$code})", $code);
    }
}

if (!function_exists('e')) {
    /** HTML escape: <?= e($user->name) ?> */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('lang_attrs')) {
    /**
     * html attributes for the current locale: lang="ar" dir="rtl"
     * Use in every template: <html <?= lang_attrs() ?>>
     */
    function lang_attrs(?string $locale = null): string
    {
        $locale = $locale ?? Lang::locale();
        return sprintf('lang="%s" dir="%s"', $locale, Lang::isRtl($locale) ? 'rtl' : 'ltr');
    }
}

if (!function_exists('app')) {
    /** app(UserService::class) → auto-resolved instance */
    function app(string $abstract): mixed
    {
        return Container::getInstance()->get($abstract);
    }
}

if (!function_exists('route')) {
    /** route('users.show', ['id' => 1]) → /user/1 */
    function route(string $name, array $params = []): string
    {
        return Router::getInstance()->url($name, $params);
    }
}

if (!function_exists('csrf_token')) {
    /** Get or generate CSRF token */
    function csrf_token(): string
    {
        return request()->csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    /** Render CSRF hidden input field */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('event')) {
    /** Fire an event: event('user.created', $user) */
    function event(string $name, mixed ...$args): array
    {
        return Event::fire($name, ...$args);
    }
}

if (!function_exists('listen')) {
    /** Register event listener: listen('user.created', fn($user) => ...) */
    function listen(string $event, callable $listener): void
    {
        Event::listen($event, $listener);
    }
}

if (!function_exists('transaction')) {
    /** Execute callback inside DB transaction */
    function transaction(callable $callback): mixed
    {
        return Database::connection()->transaction($callback);
    }
}

if (!function_exists('session')) {
    /**
     * session() → all session data
     * session('key') → value
     * session('key', 'default') → with default
     * session(['key' => 'value']) → store
     */
    function session(string|array|null $key = null, mixed $default = null): mixed
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                Session::put($k, $v);
            }
            return null;
        }
        if ($key === null) {
            return Session::all();
        }
        return Session::get($key, $default);
    }
}

if (!function_exists('flash')) {
    /** Flash data for next request only: flash('success', 'Saved!') */
    function flash(string $key, mixed $value): void
    {
        Session::flash($key, $value);
    }
}

if (!function_exists('old')) {
    /** Old input after validation redirect: old('name', $user->name) */
    function old(string $key, mixed $default = null): mixed
    {
        $old = Session::get('_old', []);
        return is_array($old) ? ($old[$key] ?? $default) : $default;
    }
}

if (!function_exists('errors')) {
    /** Validation errors after redirect: errors()['email'] ?? [] */
    function errors(?string $field = null): mixed
    {
        $all = Session::get('_errors', []);
        if (!is_array($all)) {
            return $field === null ? [] : [];
        }
        if ($field === null) {
            return $all;
        }
        return $all[$field] ?? [];
    }
}

if (!function_exists('back')) {
    /**
     * Redirect to previous page — the local page, never wherever a client
     * claims. Referer is attacker-controlled, so an unchecked back() turns any
     * POST into an open redirect off-site. The trust decision lives in
     * Request::sameSiteUrl(); keep back() and App::run() on the same door.
     */
    function back(): Response
    {
        return redirect(\Widdx\Request::sameSiteReferer());
    }
}

if (!function_exists('auth')) {
    /** Current logged-in user or null */
    function auth(): mixed
    {
        return Auth::user();
    }
}

if (!function_exists('cache')) {
    /**
     * cache() → FileCache instance
     * cache('key') → value
     * cache('key', 'default') → with default
     * cache(['key' => 'value'], 3600) → store with TTL
     */
    function cache(string|array|null $key = null, mixed $default = null, int $ttl = 0): mixed
    {
        $container = Container::getInstance();
        try {
            $instance = $container->get(CacheInterface::class);
        } catch (\Throwable) {
            // Not booted via App::run() (CLI/tests): fall back to the configured driver.
            $class = ((string) App::getConfig('cache.default', 'file')) === 'array'
                ? ArrayCache::class
                : FileCache::class;
            $instance = $container->get($class);
        }
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $instance->put($k, $v, $ttl);
            }
            return null;
        }
        if ($key === null) {
            return $instance;
        }
        return $instance->get($key, $default);
    }
}

if (!function_exists('logger')) {
    /**
     * logger() → FileLogger instance
     * logger('error', 'message') → log with level
     */
    function logger(?string $level = null, string $message = '', array $context = []): mixed
    {
        $container = Container::getInstance();
        try {
            $instance = $container->get(LoggerInterface::class);
        } catch (\Throwable) {
            // Not booted via App::run() (CLI/tests): use the default file logger.
            $instance = $container->get(FileLogger::class);
        }
        if ($level === null) {
            return $instance;
        }
        return $instance->log($level, $message, $context);
    }
}

if (!function_exists('storage')) {
    /**
     * storage() → LocalStorage instance
     * storage('path') → file contents
     */
    function storage(?string $path = null): mixed
    {
        $container = Container::getInstance();
        try {
            $instance = $container->get(StorageInterface::class);
        } catch (\Throwable) {
            // Not booted via App::run() (CLI/tests): use local disk storage.
            $instance = $container->get(LocalStorage::class);
        }
        if ($path === null) {
            return $instance;
        }
        return $instance->get($path);
    }
}

if (!function_exists('storageRoutes')) {
    /**
     * Serve web-accessible uploads over HTTP — the ONLY safe way to expose
     * stored files. Registers GET {prefix}/{path} serving storage/app/public
     * (never the rest of storage/app) with real Content-Type + nosniff and
     * hard traversal protection. Call once in routes/web.php:
     *   storageRoutes(); // files stored via ->store('public/uploads')
     *                    // become http://site/storage/uploads/<name>
     */
    function storageRoutes(string $prefix = '/storage'): void
    {
        $root = (defined('WIDDX_BASE_PATH') ? WIDDX_BASE_PATH : dirname(__DIR__)) . '/storage/app/public';
        get("{$prefix}/{path:.+}", function (string $path) use ($root): Response {
            if ($path === '' || str_contains($path, "\0")
                || str_starts_with($path, '/') || preg_match('#^[a-zA-Z]:#', $path) === 1) {
                abort(404);
            }
            $segments = [];
            foreach (explode('/', str_replace('\\', '/', $path)) as $seg) {
                if ($seg === '' || $seg === '.') {
                    continue;
                }
                if ($seg === '..') {
                    abort(404);
                }
                $segments[] = $seg;
            }
            $realRoot = realpath($root);
            $real = realpath($root . '/' . implode('/', $segments));
            if ($realRoot === false || $real === false || !is_file($real)
                || !str_starts_with($real, $realRoot . '/')) {
                abort(404);
            }
            $mime = 'application/octet-stream';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    // No finfo_close(): deprecated in 8.5, the object frees itself.
                    $detected = finfo_file($finfo, $real);
                    if (is_string($detected) && $detected !== '') {
                        $mime = $detected;
                    }
                }
            }
            // Never hand the browser a runnable/script file, whatever it contains.
            if (preg_match('#^(text/x-|application/(x-sh|x-httpd-php|php))#', $mime) === 1) {
                $mime = 'text/plain';
            }
            return Response::make((string) file_get_contents($real))
                ->setHeader('Content-Type', $mime)
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setHeader('Content-Disposition', 'inline; filename="' . basename($real) . '"');
        })->name('storage.serve');
    }
}

if (!function_exists('queue')) {
    /**
     * queue() → Queue instance
     * queue(Job::class, ['key' => 'value']) → push job
     */
    function queue(?string $job = null, array $data = [], string $queueName = 'default'): mixed
    {
        $container = Container::getInstance();
        try {
            $instance = $container->get(QueueInterface::class);
        } catch (\Throwable) {
            // Not booted via App::run() (CLI/tests): fall back to the configured driver.
            $class = ((string) App::getConfig('queue.default', 'sync')) === 'database'
                ? DatabaseQueue::class
                : SyncQueue::class;
            $instance = $container->get($class);
        }
        if ($job === null) {
            return $instance;
        }
        return $instance->push($job, $data, $queueName);
    }
}

if (!function_exists('mail')) {
    /**
     * mail() → Mail instance
     * mail('to@example.com', 'Subject', 'template', ['key' => 'value']) → send
     */
    function mail(?string $to = null, string $subject = '', string $template = '', array $data = []): mixed
    {
        $container = Container::getInstance();
        try {
            $instance = $container->get(MailInterface::class);
        } catch (\Throwable) {
            // Not booted via App::run() (CLI/tests): fall back to the configured driver.
            $class = ((string) App::getConfig('mail.default', 'log')) === 'smtp'
                ? SmtpMail::class
                : LogMail::class;
            $instance = $container->get($class);
        }
        if ($to === null) {
            return $instance;
        }
        return $instance->send($to, $subject, $template, $data);
    }
}

if (!function_exists('singular')) {
    /** posts → post, categories → category, addresses → address */
    function singular(string $word): string
    {
        $w = strtolower($word);
        if (strlen($w) > 4 && str_ends_with($w, 'ies')) {
            return substr($w, 0, -3) . 'y';
        }
        if (preg_match('/(ch|sh|x|z|ss)es$/', $w)) {
            return substr($w, 0, -2);
        }
        if (str_ends_with($w, 's') && !str_ends_with($w, 'ss')) {
            return substr($w, 0, -1);
        }
        return $w;
    }
}

if (!function_exists('resource')) {
    /**
     * Register full CRUD routes for a resource.
     *
     *   resource('posts');                    // ZERO code: finds App\Controllers\PostController,
     *                                         // else drives App\Models\Post with built-in views
     *   resource('users', UserController::class); // explicit controller
     *   resource('posts')->public();          // opt out of the fail-closed auth gate
     *
     * Creates: GET/POST /posts, GET/PUT/DELETE /posts/{id} + named routes.
     * Returns a RouteGroup so ->public()/->middleware() apply to all 7 routes.
     */
    function resource(string $name, ?string $controller = null): \Widdx\RouteGroup
    {
        $name = strtolower($name);
        $routes = [];

        if ($controller === null) {
            $studly = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', singular($name))));
            $candidate = 'App\\Controllers\\' . $studly . 'Controller';
            if (class_exists($candidate)) {
                $controller = $candidate;
            } else {
                $model = 'App\\Models\\' . $studly;
                if (!class_exists($model)) {
                    throw new BindingException(
                        "resource('{$name}') found neither {$candidate} nor {$model}. " .
                        'Create the model (php widdx make:model ' . $studly . ') or pass the controller class.'
                    );
                }
                // Model-only: drive the generic CRUD controller with built-in views.
                $mk = fn() => new \Widdx\ResourceController($model);
                $routes[] = get("/{$name}", fn() => $mk()->index())->name("{$name}.index");
                $routes[] = get("/{$name}/create", fn() => $mk()->create())->name("{$name}.create");
                $routes[] = post("/{$name}", fn() => $mk()->store())->name("{$name}.store");
                $routes[] = get("/{$name}/{id}", fn(string $id) => $mk()->show($id))->name("{$name}.show");
                $routes[] = get("/{$name}/{id}/edit", fn(string $id) => $mk()->edit($id))->name("{$name}.edit");
                $routes[] = put("/{$name}/{id}", fn(string $id) => $mk()->update($id))->name("{$name}.update");
                $routes[] = delete("/{$name}/{id}", fn(string $id) => $mk()->destroy($id))->name("{$name}.destroy");
                return new \Widdx\RouteGroup($routes);
            }
        }

        $routes[] = get("/{$name}", [$controller, 'index'])->name("{$name}.index");
        $routes[] = get("/{$name}/create", [$controller, 'create'])->name("{$name}.create");
        $routes[] = post("/{$name}", [$controller, 'store'])->name("{$name}.store");
        $routes[] = get("/{$name}/{id}", [$controller, 'show'])->name("{$name}.show");
        $routes[] = get("/{$name}/{id}/edit", [$controller, 'edit'])->name("{$name}.edit");
        $routes[] = put("/{$name}/{id}", [$controller, 'update'])->name("{$name}.update");
        $routes[] = delete("/{$name}/{id}", [$controller, 'destroy'])->name("{$name}.destroy");
        return new \Widdx\RouteGroup($routes);
    }
}

if (!function_exists('apiResource')) {
    /**
     * Register API CRUD routes (returns JSON).
     * Usage: apiResource('posts'); // or apiResource('posts', PostController::class);
     *        apiResource('posts')->public(); // opt out of the fail-closed auth gate
     */
    function apiResource(string $name, ?string $controller = null): \Widdx\RouteGroup
    {
        $name = strtolower($name);
        $routes = [];

        if ($controller === null) {
            $studly = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', singular($name))));
            $candidate = 'App\\Controllers\\' . $studly . 'Controller';
            if (class_exists($candidate)) {
                $controller = $candidate;
            } else {
                $model = 'App\\Models\\' . $studly;
                if (!class_exists($model)) {
                    throw new BindingException(
                        "apiResource('{$name}') found neither {$candidate} nor {$model}. " .
                        'Create the model (php widdx make:model ' . $studly . ') or pass the controller class.'
                    );
                }
                $mk = fn() => new \Widdx\ResourceApiController($model);
                $routes[] = get("/api/{$name}", fn() => $mk()->index())->name("api.{$name}.index");
                $routes[] = post("/api/{$name}", fn() => $mk()->store())->name("api.{$name}.store");
                $routes[] = get("/api/{$name}/{id}", fn(string $id) => $mk()->show($id))->name("api.{$name}.show");
                $routes[] = put("/api/{$name}/{id}", fn(string $id) => $mk()->update($id))->name("api.{$name}.update");
                $routes[] = patch("/api/{$name}/{id}", fn(string $id) => $mk()->update($id))->name("api.{$name}.patch");
                $routes[] = delete("/api/{$name}/{id}", fn(string $id) => $mk()->destroy($id))->name("api.{$name}.destroy");
                return new \Widdx\RouteGroup($routes);
            }
        }

        $routes[] = get("/api/{$name}", [$controller, 'index'])->name("api.{$name}.index");
        $routes[] = post("/api/{$name}", [$controller, 'store'])->name("api.{$name}.store");
        $routes[] = get("/api/{$name}/{id}", [$controller, 'show'])->name("api.{$name}.show");
        $routes[] = put("/api/{$name}/{id}", [$controller, 'update'])->name("api.{$name}.update");
        $routes[] = patch("/api/{$name}/{id}", [$controller, 'update'])->name("api.{$name}.patch");
        $routes[] = delete("/api/{$name}/{id}", [$controller, 'destroy'])->name("api.{$name}.destroy");
        return new \Widdx\RouteGroup($routes);
    }
}

if (!function_exists('layout')) {
    /**
     * Render a view inside a layout.
     * Usage: return layout('layouts.app', ['title' => 'Home'], $content);
     */
    function layout(string $layout, array $data = [], ?string $content = null): string
    {
        if ($content === null) {
            $content = ob_get_clean();
        }
        $data['content'] = $content;
        return view($layout, $data);
    }
}

if (!function_exists('component')) {
    /**
     * Render a reusable component.
     * Usage: echo component('components.alert', ['type' => 'success', 'message' => 'Done!']);
     */
    function component(string $name, array $data = []): string
    {
        return view($name, $data);
    }
}

if (!function_exists('paginate')) {
    /**
     * Paginate query results.
     * Usage: $users = paginate(User::query(), 10);
     */
    function paginate(QueryBuilder $query, int $perPage = 15): array
    {
        $page = (int) (request('page', 1));
        $page = max(1, $page);
        $total = $query->count();
        $pages = (int) ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;

        $items = $query->limit($perPage)->offset($offset)->get();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'hasMore' => $page < $pages,
        ];
    }
}

// ---------- Route registration shortcuts (used in routes/web.php) ----------

if (!function_exists('get')) {
    function get(string $uri, mixed $action): Route
    {
        return Router::get($uri, $action);
    }
}
if (!function_exists('post')) {
    function post(string $uri, mixed $action): Route
    {
        return Router::post($uri, $action);
    }
}
if (!function_exists('put')) {
    function put(string $uri, mixed $action): Route
    {
        return Router::put($uri, $action);
    }
}
if (!function_exists('patch')) {
    function patch(string $uri, mixed $action): Route
    {
        return Router::patch($uri, $action);
    }
}
if (!function_exists('delete')) {
    function delete(string $uri, mixed $action): Route
    {
        return Router::delete($uri, $action);
    }
}

if (!function_exists('authRoutes')) {
    /**
     * ONE line = the complete auth flow: login, register, logout,
     * password reset and email verification — routes, controller and
     * built-in views all shipped by the framework.
     * Override any screen by adding views/auth/<name>.php in your project.
     */
    function authRoutes(): void
    {
        $c = fn(string $m, array $args = []) => (new \Widdx\AuthController())->{$m}(...$args);
        // Credential endpoints are brute-force targets: 5 attempts per minute
        // per client IP each.
        $throttle = fn() => new \Widdx\ThrottleMiddleware(5, 60);
        get('/login', fn() => $c('showLogin'))->name('login');
        post('/login', fn() => $c('login'))->middleware($throttle());
        get('/register', fn() => $c('showRegister'))->name('register');
        post('/register', fn() => $c('register'))->middleware($throttle());
        post('/logout', fn() => $c('logout'))->name('logout');
        get('/forgot-password', fn() => $c('showForgot'))->name('password.request');
        post('/forgot-password', fn() => $c('sendResetLink'))->middleware($throttle());
        get('/reset-password/{token}', fn(string $token) => $c('showReset', [$token]))->name('password.reset');
        post('/reset-password', fn() => $c('reset'))->name('password.update');
        get('/email-verification', fn() => $c('showVerifyNotice'))->name('verification.notice');
        post('/email-verification', fn() => $c('resendVerification'))->name('verification.resend')->middleware($throttle());
        get('/verify-email/{token}', fn(string $token) => $c('verify', [$token]))->name('verification.verify');
    }
}

if (!function_exists('authorize')) {
    /** 403 unless the current user passes the Gate. */
    function authorize(string $ability, mixed ...$args): void
    {
        if (!\Widdx\Gate::allows($ability, ...$args)) {
            throw new \Widdx\Exceptions\HttpException('This action is unauthorized.', 403);
        }
    }
}

if (!function_exists('dd')) {
    /** Dump and die (for debugging) */
    function dd(mixed ...$vars): never
    {
        foreach ($vars as $v) {
            var_dump($v);
        }
        exit(1);
    }
}
