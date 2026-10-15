<?php

declare(strict_types=1);

namespace Widdx;

/**
 * 2.4: minimal, opt-in CORS. Inert unless a route/group adds it AND
 * config/cors.php lists an allowed origin — so nothing is exposed by default.
 *
 *   Router::middlewareGroup('api', [\Widdx\CorsMiddleware::class]);
 */
class CorsMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $origins = (array) config('cors.allowed_origins', []);
        $origin = (string) ($request->header('Origin') ?? '');

        if ($origin === '' || $origins === []) {
            // Not a cross-origin request, or CORS not configured: pass through
            // untouched (the Router still answers OPTIONS with a plain 204+Allow).
            return $this->toResponse($next($request));
        }

        $allowed = in_array('*', $origins, true) || in_array($origin, $origins, true);
        if (!$allowed) {
            // Origin rejected: strip no headers, but never grant this origin.
            return $this->toResponse($next($request));
        }

        $credentials = (bool) config('cors.allow_credentials', false);
        $headers = [
            'Access-Control-Allow-Origin' => $credentials ? $origin : (in_array('*', $origins, true) ? '*' : $origin),
            'Vary' => 'Origin',
        ];
        if ($credentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        // Preflight: answer for the browser, do not hit the controller.
        if ($request->method() === 'OPTIONS'
            && ($request->header('Access-Control-Request-Method') ?? '') !== '') {
            $headers['Access-Control-Allow-Methods'] = implode(', ', (array) config('cors.allowed_methods', []));
            $reqHeaders = (string) ($request->header('Access-Control-Request-Headers') ?? '');
            $allowHeaders = (array) config('cors.allowed_headers', []);
            if ($reqHeaders !== '') {
                $allowHeaders = array_values(array_unique(array_merge($allowHeaders, array_map('trim', explode(',', $reqHeaders)))));
            }
            $headers['Access-Control-Allow-Headers'] = implode(', ', $allowHeaders);
            $headers['Access-Control-Max-Age'] = (string) config('cors.max_age', 86400);
            return Response::make('', 204, $headers);
        }

        $response = $this->toResponse($next($request));
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }
        return $response;
    }

    protected function toResponse(mixed $value): Response
    {
        return $value instanceof Response ? $value : Router::getInstance()->normalizeToResponse($value);
    }
}
