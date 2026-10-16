<?php

/**
 * Cross-Origin Resource Sharing — OFF by default.
 *
 * Nothing here takes effect until a route/group runs CorsMiddleware, e.g.:
 *   Router::middlewareGroup('api', [\Widdx\CorsMiddleware::class]);
 * Add that to routes/web.php only if browser JS on ANOTHER origin must call
 * your /api. Leaving allowed_origins empty means "no cross-origin access".
 */
return [
    // Exact origins allowed to call you, or ['*'] for any site (rarely wise).
    'allowed_origins' => [],

    // Methods a preflight may be granted (only these are ever answered).
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // ALLOWLIST, not a suggestion: a preflight is answered with the intersection
    // of this list and what the browser asked for. A header missing here (or an
    // empty list) is never granted, so a cross-origin page cannot push arbitrary
    // headers — e.g. ['Content-Type', 'Authorization'].
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],

    // Whether cookies/Authorization may be sent cross-origin. Ignored (treated as
    // false) when allowed_origins contains '*', because browsers reject that
    // combination anyway — list the exact origins if you need credentials.
    'allow_credentials' => false,

    // How long (seconds) a browser may cache the preflight answer.
    'max_age' => 86400,
];
