<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Contracts\CacheInterface;

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
        protected ?CacheInterface $cacheStore = null,
    ) {
        if ($this->max < 1) {
            throw new \InvalidArgumentException('Throttle max must be at least 1.');
        }
        if ($this->decay < 1) {
            throw new \InvalidArgumentException('Throttle decay must be at least 1 second.');
        }
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

        $cache = null;

        // Count this request atomically BEFORE deciding whether to allow it.
        // A separate get()-then-increment() check races: many simultaneous
        // requests can all observe a value below the limit and all pass.
        try {
            $cache = $this->cacheStore ?? cache();
            $hits = $cache->increment($key, 1, $this->decay);
        } catch (\Throwable) {
            $hits = false;
        }

        // A broken/unwritable counter must not silently disable a security
        // control on login/reset endpoints. Fail closed so the caller retries.
        if ($hits === false || !$cache instanceof CacheInterface) {
            return Response::json(['error' => 'Rate limiter temporarily unavailable'], 503)
                ->setHeader('Retry-After', (string) $this->decay);
        }

        // Fixed window: only the request that atomically creates the counter
        // records its end time. The counter driver preserves its original TTL.
        if ($hits === 1) {
            $cache->put($windowKey, time() + $this->decay, $this->decay);
        }

        if ($hits > $this->max) {
            $windowEnd = (int) $cache->get($windowKey, time() + $this->decay);
            $retryAfter = max(1, $windowEnd - time());
            return Response::json(['error' => 'Too Many Requests'], 429)
                ->setHeader('Retry-After', (string) $retryAfter)
                ->setHeader('X-RateLimit-Limit', (string) $this->max)
                ->setHeader('X-RateLimit-Remaining', '0');
        }

        $response = $next($request);
        if ($response instanceof Response) {
            $response
                ->setHeader('X-RateLimit-Limit', (string) $this->max)
                ->setHeader('X-RateLimit-Remaining', (string) max(0, $this->max - $hits));
            return $response;
        }
        return Router::getInstance()->normalizeToResponse($response);
    }
}
