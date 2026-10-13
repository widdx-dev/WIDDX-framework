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
class Route
{
    /** @var array<int, string|callable|object> */
    public array $middlewares = [];

    public function __construct(
        public array $methods,
        public string $uri,
        public mixed $action,
        public ?string $name = null,
        protected string $regex = '',
        protected array $paramNames = [],
    ) {
        $this->compile();
    }

    /** Add one or more middleware to this route */
    public function middleware(string|callable|object|array $middleware): self
    {
        $list = is_array($middleware) ? $middleware : [$middleware];
        foreach ($list as $m) {
            $this->middlewares[] = $m;
        }
        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /** Convert /user/{id} to regex with named parameter extraction */
    protected function compile(): void
    {
        $names = [];
        // Split on {param} / {param:regex} placeholders (whole token captured).
        $parts = preg_split(
            '/(\{[a-zA-Z_][a-zA-Z0-9_]*(?::[^}]+)?\})/',
            $this->uri,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        ) ?: [];

        $regex = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                // Full placeholder token, e.g. {id} or {id:\d+}.
                if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}$/', $part, $m)) {
                    $names[] = $m[1];
                    $inner = $m[2] ?? '[^/]+';
                    $regex .= '(?P<' . $m[1] . '>' . $inner . ')';
                }
            } else {
                // Literal segment — quote so '.', '+'… stay literal.
                $regex .= preg_quote($part, '#');
            }
        }
        $this->paramNames = $names;
        $this->regex = '#^' . $regex . '$#';
    }

    /** Match actual URI → parameter array or null */
    public function match(string $uri): ?array
    {
        if (preg_match($this->regex, $uri, $matches)) {
            $params = [];
            foreach ($this->paramNames as $name) {
                $params[$name] = urldecode((string) ($matches[$name] ?? ''));
            }
            return $params;
        }
        return null;
    }
}

class Router
{
    protected static ?self $instance = null;

    /** @var Route[] */
    protected array $routes = [];

    /** Global middleware executed on every request */
    protected array $globalMiddlewares = [];

    /** @var array<string, array> Named route groups for middleware */
    protected array $middlewareGroups = [];

    public static function getInstance(): self
    {
        if (static::$instance === null) {
            static::$instance = new self();
        }
        return static::$instance;
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

    /** Register a global middleware */
    public static function use(string|callable|object $middleware): void
    {
        static::getInstance()->globalMiddlewares[] = $middleware;
    }

    /** Register a named middleware group */
    public static function group(string $name, array $middlewares): void
    {
        static::getInstance()->middlewareGroups[$name] = $middlewares;
    }

    public function add(array $methods, string $uri, mixed $action): Route
    {
        $route = new Route($methods, $uri, $action);
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
        $method = $request->method();
        $uri = $request->uri();

        /** @var string[] Methods registered for the same URI (405 hint) */
        $allowedForSameUri = [];

        foreach ($this->routes as $route) {
            if (!in_array($method, $route->methods, true)) {
                // Remember URI matches with other methods → 405 instead of 404.
                if ($route->match($uri) !== null) {
                    $allowedForSameUri = array_merge($allowedForSameUri, $route->methods);
                }
                continue;
            }
            $params = $route->match($uri);
            if ($params === null) {
                continue;
            }

            $request->routeParams = $params;
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

            return $pipeline($request);
        }

        if ($allowedForSameUri !== []) {
            throw new Exceptions\MethodNotAllowedException(
                "Method [{$method}] is not allowed for [{$uri}]. Allowed: " . implode(', ', array_unique($allowedForSameUri)) . '.'
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
                }
                return $url;
            }
        }
        throw new Exceptions\RouteNotFoundException("Named route [{$name}] not found.");
    }
}
