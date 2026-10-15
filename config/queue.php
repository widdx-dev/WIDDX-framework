<?php
// Queue configuration
return [
    // Default driver: sync | database
    'default' => env('QUEUE_DRIVER', 'sync'),

    // 2.5: how long a reserved job stays hidden from other workers before it
    // is considered abandoned (worker crashed) and re-claimable. Seconds.
    'retry_after' => (int) env('QUEUE_RETRY_AFTER', 90),

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
