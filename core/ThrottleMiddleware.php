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
 * 2.10: the key is client IP + method + the ROUTE PATTERN (e.g. /users/{id}),
 * not the raw URI — so one resource does not spawn unlimited counters. The
 * window is FIXED: expiry is set on the first hit only, so a client that keeps
 * hammering is released on schedule instead of being pushed forward forever.
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
        $path = $request->routePattern !== '' ? $request->routePattern : $request->uri();
        $key = 'throttle:' . sha1($ip . '|' . $request->method() . '|' . $path);
        $windowKey = $key . ':window_end';

        $cache = cache();
        $hits = (int) $cache->get($key, 0);

        if ($hits >= $this->max) {
            $windowEnd = (int) $cache->get($windowKey, time() + $this->decay);
            $retryAfter = max(1, $windowEnd - time());
            return Response::json(['error' => 'Too Many Requests'], 429)
                ->setHeader('Retry-After', (string) $retryAfter)
                ->setHeader('X-RateLimit-Remaining', '0');
        }

        // Fixed window: record the reset instant on the FIRST hit only; the
        // atomic counter keeps its expiry, so the window never slides.
        if ($hits === 0) {
            $cache->put($windowKey, time() + $this->decay, $this->decay);
        }
        $cache->increment($key, 1, $this->decay);

        return $next($request);
    }
}
