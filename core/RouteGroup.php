<?php

declare(strict_types=1);

namespace Widdx;

/**
 * A handle on every Route registered by one resource()/apiResource() call,
 * so modifiers apply to the whole group:
 *
 *   resource('posts')->public();
 *   apiResource('orders')->middleware(new AuthMiddleware());
 *
 * Kept deliberately tiny: it only forwards to the underlying Route objects.
 */
class RouteGroup
{
    /** @var Route[] */
    protected array $routes;

    /** @param Route[] $routes */
    public function __construct(array $routes = [])
    {
        $this->routes = $routes;
    }

    /** Opt the whole group out of the fail-closed authorization gate. */
    public function public(): self
    {
        foreach ($this->routes as $route) {
            $route->public();
        }
        return $this;
    }

    /** Attach middleware to every route in the group. */
    public function middleware(string|callable|object|array $middleware): self
    {
        foreach ($this->routes as $route) {
            $route->middleware($middleware);
        }
        return $this;
    }

    /** @return Route[] */
    public function routes(): array
    {
        return $this->routes;
    }
}
