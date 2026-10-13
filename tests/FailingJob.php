<?php

declare(strict_types=1);

namespace Widdx\Tests;

use Widdx\Job;

/** Queue-retry test fixture: always throws, retries once, no sleep. */
class FailingJob extends Job
{
    protected int $tries = 2;

    protected int $backoff = 0;

    public function handle(array $data): void
    {
        throw new \RuntimeException('planned failure');
    }
}
