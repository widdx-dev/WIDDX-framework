<?php

declare(strict_types=1);

namespace Widdx;

/**
 * A single registered route.
 *
 * Lives in its own file (PSR-4: Widdx\Route → core/Route.php). The Router
 * holds a list of these; each compiles its URI into a regex once, at
 * registration time.
 */
class Route
{
    /** @var array<int, string|callable|object> */
    public array $middlewares = [];

    protected bool $isPublic = false;

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

    /** Mark this route as publicly accessible (no auth required). */
    public function public(): self
    {
        $this->isPublic = true;
        return $this;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
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
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        ) ?: [];

        $regex = '';
        foreach ($parts as $part) {
            // Full placeholder token, e.g. {id} or {id:\d+} — otherwise literal.
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}$/', $part, $m) === 1) {
                $names[] = $m[1];
                $inner = $m[2] ?? '[^/]+';
                $regex .= '(?P<' . $m[1] . '>' . $inner . ')';
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
