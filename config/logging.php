<?php
// Logger configuration
return [
    // Default channel: file
    'default' => env('LOG_CHANNEL', 'file'),

    'channels' => [
        'file' => [
            'driver' => 'file',
            'path' => dirname(__DIR__) . '/storage/logs',
        ],
    ],
];
