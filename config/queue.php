<?php
// Queue configuration
return [
    // Default driver: sync | database
    'default' => env('QUEUE_DRIVER', 'sync'),

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],
        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
        ],
    ],
];
