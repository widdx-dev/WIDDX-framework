<?php
// Cache configuration
return [
    // Default driver: file | array
    'default' => env('CACHE_DRIVER', 'file'),

    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => dirname(__DIR__) . '/storage/cache',
        ],
        'array' => [
            'driver' => 'array',
        ],
    ],
];
