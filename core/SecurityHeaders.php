<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Global middleware that stamps sensible security headers on every response.
 *
 * Enable it in routes/web.php:
 *   \Widdx\Router::use(\Widdx\SecurityHeaders::class);
 *
 * Tunable via config('app.security.*'):
 *   frame_options (default SAMEORIGIN), referrer_policy, content_type_nosniff,
 *   csp — Content-Security-Policy string, only sent when set.
 */
class SecurityHeaders
{
    public function handle(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        return static::apply($response);
    }

    /**
     * Apply the baseline headers to a final response.
     *
     * App::run() also calls this after exception rendering so 404/419/500
     * responses receive the same protections as successful route responses.
     */
    public static function apply(Response $response): Response
    {
        $response
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('X-Frame-Options', (string) config('app.security.frame_options', 'SAMEORIGIN'))
            ->setHeader('Referrer-Policy', (string) config('app.security.referrer_policy', 'strict-origin-when-cross-origin'));

        // Never leak the PHP version. This runs before the response is sent,
        // including for error responses created outside the middleware stack.
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }

        $csp = config('app.security.csp');
        if (is_string($csp) && $csp !== '') {
            $response->setHeader('Content-Security-Policy', $csp);
        }

        return $response;
    }
}
