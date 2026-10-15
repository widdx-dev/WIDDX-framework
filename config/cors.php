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

    // Methods echoed on a preflight, e.g. ['GET', 'POST', 'PUT', 'DELETE'].
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Headers echoed on a preflight, e.g. ['Content-Type', 'Authorization'].
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],

    // Whether cookies/Authorization may be sent cross-origin. Cannot be used
    // with allowed_origins = ['*'] (browsers reject that combination).
    'allow_credentials' => false,

    // How long (seconds) a browser may cache the preflight answer.
    'max_age' => 86400,
];
