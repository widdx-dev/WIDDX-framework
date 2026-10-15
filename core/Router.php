<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Exceptions\RouteNotFoundException;

/**
 * Router
 *
 * Supports: GET, POST, PUT, PATCH, DELETE + dynamic {id} params + simple middleware.
 *
 * Example:
 *   get('/', fn() => 'Welcome to Widdx!');
 *   get('/users', [UserController::class, 'index']);
 *   get('/user/{id}', [UserController::class, 'show']);
 */
class Router
{
    protected static ?self $instance = null;

    /** @var Route[] */
    protected array $routes = [];

    /** Global middleware executed on every request */
    protected array $globalMiddlewares = [];

    /** @var array<string, array> Named route groups for middleware */
    protected array $middlewareGroups = [];

    /**
     * Ambient defaults applied by add() to every route registered right now
     * (group name or null) — App::run sets 'web' while routes/web.php loads.
     */
    protected ?string $ambientGroup = null;

    /** Nested Router::group()['prefix'] stack, joined without leading slash. */
    protected string $pendingPrefix = '';

    /** Middleware queued by the enclosing Router::group() call. */
    protected array $pendingGroupMiddleware = [];

    /** Is the route currently being dispatched marked ->public()? */
    protected bool $currentRoutePublic = false;

    public static function getInstance(): self
    {
        if (static::$instance === null) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * True while dispatching a route that was explicitly marked public.
     * CRUD controllers consult this for the fail-closed authorization gate.
     */
    public function currentRouteIsPublic(): bool
    {
        return $this->currentRoutePublic;
    }

    /** Reset (useful for testing) */
    public static function reset(): void
    {
        static::$instance = null;
    }

    // ---------- Route Registration ----------

    public static function get(string $uri, mixed $action): Route
    {
        return static::getInstance()->add(['GET'], $uri, $action);
    }

    public static function post(string $uri, mixed $action): Route
    {
        return static::getInstance()->add(['POST'], $uri, $action);
    }

    public static function put(string $uri, mixed $action): Route
    {
        return static::getInstance()->add(['PUT'], $uri, $action);
    }

    public static function patch(string $uri, mixed $action): Route
    {
        return static::getInstance()->add(['PATCH'], $uri, $action);
    }

    public static function delete(string $uri, mixed $action): Route
    {
        return static::getInstance()->add(['DELETE'], $uri, $action);
    }

    public static function any(string $uri, mixed $action): Route
    {
        return static::getInstance()->add(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $uri, $action);
    }

    /** Register a global middleware (applied to every route, every group) */
    public static function use(string|callable|object $middleware): void
    {
        static::getInstance()->globalMiddlewares[] = $middleware;
    }

    /** Register a named middleware group ('web', 'api', ...). */
    public static function middlewareGroup(string $name, array $middlewares): void
    {
        static::getInstance()->middlewareGroups[$name] = $middlewares;
    }

    /**
     * Dual-purpose grouping (the old named-group call is kept working):
     *
     *   Router::group('web', [Csrf::class]);            // deprecated alias of
     *                                                   // middlewareGroup()
     *   Router::group(['prefix' => '/admin',
     *                  'middleware' => [Auth::class]],
     *          function () { get('/users', ...); });    // scoped route group
     */
    public static function group(string|array $nameOrOptions, array|callable $definition): void
    {
        if (is_string($nameOrOptions) && is_array($definition)) {
            static::middlewareGroup($nameOrOptions, $definition);
            return;
        }
        if (is_array($nameOrOptions) && is_callable($definition)) {
            static::scopedGroup($nameOrOptions, $definition);
            return;
        }
        throw new Exceptions\WiddxException(
            'Router::group() takes either (string $name, array $middlewares) '
            . 'or (array $options, callable $routes).'
        );
    }

    /** Apply settings to every route registered inside $callback. */
    public static function scopedGroup(array $options, callable $callback): void
    {
        $r = static::getInstance();
        $savedPrefix = $r->pendingPrefix;
        $savedMw = $r->pendingGroupMiddleware;
        $savedAmbient = $r->ambientGroup;

        if (isset($options['prefix'])) {
            $r->pendingPrefix .= '/' . trim((string) $options['prefix'], '/');
        }
        if (isset($options['middleware'])) {
            $list = (array) $options['middleware'];
            $r->pendingGroupMiddleware = array_merge($r->pendingGroupMiddleware, $list);
        }
        if (isset($options['group'])) {
            $r->ambientGroup = (string) $options['group'];
        }

        try {
            $callback();
        } finally {
            $r->pendingPrefix = $savedPrefix;
            $r->pendingGroupMiddleware = $savedMw;
            $r->ambientGroup = $savedAmbient;
        }
    }

    /** Prefix every route registered inside $callback. */
    public static function prefix(string $prefix, callable $callback): void
    {
        static::scopedGroup(['prefix' => $prefix], $callback);
    }

    /** Set (null = off) the group applied to routes registered from now on. */
    public function setAmbientGroup(?string $group): void
    {
        $this->ambientGroup = $group;
    }

    public function add(array $methods, string $uri, mixed $action): Route
    {
        $full = ($this->pendingPrefix !== '' ? $this->pendingPrefix : '') . $uri;
        // Ambient group, with one automatic exception: while routes/web.php
        // registers into 'web', anything under /api belongs to 'api' instead
        // (no CSRF — there is no browser form to protect).
        $group = $this->ambientGroup;
        if ($group === 'web' && (str_starts_with($full, '/api/') || $full === '/api')) {
            $group = 'api';
        }
        $groupMw = ($group !== null && isset($this->middlewareGroups[$group]))
            ? $this->middlewareGroups[$group]
            : [];
        $route = new Route($methods, $full, $action);
        foreach (array_merge($groupMw, $this->pendingGroupMiddleware) as $mw) {
            $route->middleware($mw);
        }
        $this->routes[] = $route;
        return $route;
    }

    /** @return Route[] */
    public function all(): array
    {
        return $this->routes;
    }

    // ---------- Dispatch ----------

    /**
     * Find matching route then execute with middleware and auto-injection.
     */
    public function dispatch(Request $request): Response
    {
        $requested = strtoupper($request->method());
        // 2.4: HEAD is a GET whose response body the server must not send.
        $isHead = $requested === 'HEAD';
        $method = $isHead ? 'GET' : $requested;
        $uri = $request->uri();

        /** @var string[] Methods registered for the same URI (405 hint) */
        $allowedForSameUri = [];
        /** First route matching this URI, used to answer OPTIONS requests. */
        $optionRoute = null;

        foreach ($this->routes as $route) {
            if (!in_array($method, $route->methods, true)) {
                // Remember URI matches with other methods → 405 instead of 404.
                if ($route->match($uri) !== null) {
                    $allowedForSameUri = array_merge($allowedForSameUri, $route->methods);
                    if ($requested === 'OPTIONS' && $optionRoute === null) {
                        $optionRoute = $route;
                    }
                }
                continue;
            }
            $params = $route->match($uri);
            if ($params === null) {
                continue;
            }

            $request->routeParams = $params;
            $request->routePattern = $route->uri;
            $this->currentRoutePublic = $route->isPublic();
            $container = Container::getInstance();

            // Middleware chain: global then route-specific
            $middlewares = array_merge($this->globalMiddlewares, $route->middlewares);

            $core = fn(Request $req) => $this->normalizeToResponse(
                $container->call($route->action, $params)
            );

            // Wrap middleware around core (last executes first → reverse)
            $pipeline = $core;
            foreach (array_reverse($middlewares) as $mw) {
                $next = $pipeline;
                $pipeline = fn(Request $req) => $this->runMiddleware($mw, $req, $next);
            }

            $response = $pipeline($request);
            if ($isHead) {
                $response->withoutBody();
            }
            return $response;
        }

        // 2.4: OPTIONS with no explicit route still answers correctly — run the
        // matched route's middleware (so an enabled CorsMiddleware can decorate
        // the reply) around a 204 core carrying the Allow list.
        if ($requested === 'OPTIONS' && $optionRoute instanceof Route) {
            $allow = implode(', ', array_values(array_unique(array_merge($allowedForSameUri, ['HEAD', 'OPTIONS']))));
            $core = function (Request $req) use ($allow): Response {
                return Response::make('', 204)->setHeader('Allow', $allow);
            };
            $middlewares = array_merge($this->globalMiddlewares, $optionRoute->middlewares);
            $pipeline = $core;
            foreach (array_reverse($middlewares) as $mw) {
                $next = $pipeline;
                $pipeline = fn(Request $req) => $this->runMiddleware($mw, $req, $next);
            }
            $response = $pipeline($request);
            if (!array_key_exists('Allow', $response->getHeaders())) {
                $response->setHeader('Allow', $allow);
            }
            return $response;
        }

        if ($allowedForSameUri !== []) {
            throw new Exceptions\MethodNotAllowedException(
                "Method [{$requested}] is not allowed for [{$uri}]. Allowed: " . implode(', ', array_unique($allowedForSameUri)) . '.'
            );
        }

        throw new RouteNotFoundException(
            "No route matches [{$method} {$uri}]. " .
            "Check routes/web.php — is this route registered? Is the method (GET/POST/...) correct?"
        );
    }

    /**
     * Run a single middleware.
     * Supported formats:
     *  - Closure: fn($request, $next) => Response
     *  - Class with handle($request, $next)
     *  - Object with handle()
     */
    protected function runMiddleware(string|callable|object $mw, Request $request, callable $next): Response
    {
        if (is_string($mw) && class_exists($mw)) {
            $mw = Container::getInstance()->get($mw);
        }

        if (is_callable($mw) && !is_string($mw)) {
            $result = $mw($request, $next);
        } elseif (is_object($mw) && method_exists($mw, 'handle')) {
            $result = $mw->handle($request, $next);
        } else {
            throw new Exceptions\WiddxException('Middleware must be a Closure or a class with handle($request, $next).');
        }

        return $this->normalizeToResponse($result);
    }

    /**
     * Convert any controller return value to Response:
     *  - Response → as-is
     *  - array → JSON
     *  - string/int/null → HTML
     */
    public function normalizeToResponse(mixed $value): Response
    {
        if ($value instanceof Response) {
            return $value;
        }
        if (is_array($value)) {
            return Response::json($value);
        }
        return Response::make((string) ($value ?? ''));
    }

    /**
     * Generate URL for a named route.
     * Example: route('users.show', ['id' => 1]) → /user/1
     */
    public function url(string $name, array $params = []): string
    {
        foreach ($this->routes as $route) {
            if ($route->name === $name) {
                $url = $route->uri;
                foreach ($params as $key => $value) {
                    // Encode values so they can't break the URL structure.
                    $url = str_replace('{' . $key . '}', rawurlencode((string) $value), $url);
                    // Also fill the constrained form {key:regex} when given.
                    $url = preg_replace('/\{' . preg_quote($key, '/') . ':([^}]+)\}/', rawurlencode((string) $value), $url) ?? $url;
                }
                // 2.4: never emit a URL that still carries an unfilled placeholder.
                if (preg_match('/\{[a-zA-Z_][a-zA-Z0-9_]*/', $url) === 1) {
                    throw new Exceptions\WiddxException(
                        "Cannot build URL for route [{$name}]: {$route->uri} still has an unfilled {param}."
                    );
                }
                return $url;
            }
        }
        throw new Exceptions\RouteNotFoundException("Named route [{$name}] not found.");
    }
}
