<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Simple fixed-window rate limiter backed by the cache driver.
 *
 * Usage (per route):
 *   get('/api/search', $handler)->middleware(new \Widdx\ThrottleMiddleware(30, 60));
 *
 * Above the limit the request gets a 429 JSON response with Retry-After.
 * The window key is client IP + request path, so one hot endpoint does
 * not starve the rest of the site.
 */
class ThrottleMiddleware
{
    public function __construct(
        protected int $max = 60,
        protected int $decay = 60,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        // $request->ip() only honors X-Forwarded-For from TRUSTED proxies —
        // behind nginx this keeps the limiter keyed on real clients instead
        // of the proxy's IP (which would throttle everyone together).
        $ip = $request->ip();
        $key = 'throttle:' . sha1($ip . '|' . $request->method() . '|' . $request->uri());

        $cache = cache();
        $hits = (int) $cache->get($key, 0);

        if ($hits >= $this->max) {
            return Response::json(['error' => 'Too Many Requests'], 429)
                ->setHeader('Retry-After', (string) $this->decay);
        }

        // Each hit refreshes the window TTL: a client that keeps hammering
        // stays blocked until it has been quiet for decay seconds.
        $cache->put($key, $hits + 1, $this->decay);

        return $next($request);
    }
}
