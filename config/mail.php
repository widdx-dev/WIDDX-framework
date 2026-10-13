<?php
// Mail configuration
return [
    // Default driver: log | smtp
    'default' => env('MAIL_DRIVER', 'log'),

    'from' => env('MAIL_FROM', 'noreply@example.com'),
    'from_name' => env('MAIL_FROM_NAME', 'Widdx'),

    'mailers' => [
        'log' => [
            'driver' => 'log',
        ],
        'smtp' => [
            'driver' => 'smtp',
            'host' => env('MAIL_HOST', ''),
            'port' => (int) env('MAIL_PORT', 587),
            'username' => env('MAIL_USERNAME', ''),
            'password' => env('MAIL_PASSWORD', ''),
            // '' = plain | 'tls' = STARTTLS (587) | 'ssl' = implicit SSL (465)
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'timeout' => (int) env('MAIL_TIMEOUT', 30),
            // Set false only for self-signed certs on trusted networks.
            'verify_peer' => filter_var(env('MAIL_VERIFY_PEER', 'true'), FILTER_VALIDATE_BOOLEAN),
        ],
    ],
];
