<?php

declare(strict_types=1);

namespace Widdx;

/**
 * CSRF Protection Middleware.
 *
 * Usage:
 *   Route::use(CsrfMiddleware::class); // Global
 *   get('/form', ...)->middleware(CsrfMiddleware::class); // Per-route
 *
 * In forms:
 *   <input type="hidden" name="csrf_token" value="<?= e(request()->csrfToken()) ?>">
 */
class CsrfMiddleware
{
    /**
     * Verify CSRF token on non-GET requests.
     * Returns next() if valid, throws HttpException if invalid.
     */
    public function handle(Request $request, callable $next): Response
    {
        // Safe methods must never be blocked by CSRF protection.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // API clients that do not send a session cookie are stateless and do
        // not rely on browser ambient credentials. Keep those requests usable.
        // When a session cookie is present, require a session-bound CSRF token.
        $path = parse_url((string) ($request->server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $isApi = is_string($path) && ($path === '/api' || str_starts_with($path, '/api/'));
        if ($isApi && !array_key_exists(session_name(), $request->cookies)) {
            // Stateless API clients have no ambient session credentials, so
            // they do not need a browser-session CSRF token. Any request that
            // carries the session cookie, authenticated or not, must validate it.
            return $next($request);
        }

        if (!$request->validateCsrf()) {
            // Token clients (fetch/XHR, /api paths) get machine-readable 419.
            if ($request->expectsJson()) {
                return Response::json(['error' => 'CSRF token mismatch.'], 419);
            }
            throw new Exceptions\HttpException('CSRF token mismatch. Please refresh the page and try again.', 419);
        }

        return $next($request);
    }
}
