<?php

declare(strict_types=1);

// Test job stubs (named classes so queue drivers can re-instantiate them by name)

namespace Widdx\Tests;

class TestJob extends \Widdx\Job
{
    public static string $logFile = '';

    public function handle(array $data): void
    {
        if (static::$logFile !== '') {
            file_put_contents(static::$logFile, ($data['value'] ?? 'ran'), FILE_APPEND);
        }
    }
}