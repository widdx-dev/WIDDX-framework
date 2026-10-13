<?php
// Database configuration — choose driver from .env via DB_CONNECTION.
// Directly supported drivers: sqlite, mysql, pgsql, sqlsrv, oci.
// For any other PDO driver: use the custom 'dsn' option (see example below).

return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            // Database file (auto-created if missing) or :memory: for testing
            'database' => env('DB_DATABASE', dirname(__DIR__) . '/database.sqlite'),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'widdx'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'widdx'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            // 'sslmode' => 'require', // when needed
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 1433),
            'database' => env('DB_DATABASE', 'widdx'),
            'username' => env('DB_USERNAME', 'sa'),
            'password' => env('DB_PASSWORD', ''),
        ],

        'oci' => [
            'driver' => 'oci',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 1521),
            // Service name (like XE or ORCLPDB1) or database directly as //host:port/service
            'service' => env('DB_SERVICE', 'XE'),
            'username' => env('DB_USERNAME', 'system'),
            'password' => env('DB_PASSWORD', ''),
        ],

        // Custom driver example (any PDO driver: odbc, firebird, ibm...):
        // 'custom' => [
        //     'driver'   => 'ansi', // quoting style: mysql|sqlsrv|ansi
        //     'dsn'      => env('DB_DSN', 'odbc:Driver=...;Server=...;'),
        //     'username' => env('DB_USERNAME', ''),
        //     'password' => env('DB_PASSWORD', ''),
        // ],
    ],
];
