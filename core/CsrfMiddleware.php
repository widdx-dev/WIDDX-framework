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
        // Skip safe methods
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
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
