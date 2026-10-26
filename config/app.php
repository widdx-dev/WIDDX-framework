<?php
// General application settings
return [
    // Application name (used in templates and pages)
    'name' => 'Widdx',

    // Debug mode: true shows error details, false shows friendly pages only
    // Debug OFF unless APP_DEBUG is explicitly set — a forgotten .env must
    // never expose stack traces on a public server.
    'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),

    // Locale for framework messages (lang/{locale}.php; 'en' is the fallback)
    'locale' => env('APP_LOCALE', 'en'),

    // Base URL (used when needed)
    'url' => env('APP_URL', 'http://localhost:8000'),

    // Proxy IPs allowed to set X-Forwarded-For (comma-separated in .env,
    // or '*' when exactly one trusted edge proxy sits in front).
    // Request::ip() ignores XFF from anyone not listed here.
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', ''))
    ))),

    // Baseline response headers applied automatically by the framework.
    'security' => [
        'frame_options' => env('SECURITY_FRAME_OPTIONS', 'SAMEORIGIN'),
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        // Sent only when non-empty, e.g. "default-src 'self'"
        'csp' => env('SECURITY_CSP', ''),
    ],
];
