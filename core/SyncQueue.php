<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Contracts\QueueInterface;

/**
 * Sync queue driver — executes jobs immediately.
 *
 * Useful for development and testing.
 */
class SyncQueue implements QueueInterface
{
    public function push(string $job, array $data = [], string $queue = 'default'): string
    {
        $instance = new $job();
        $instance->handle($data);
        return 'sync-' . uniqid();
    }

    public function later(int $delay, string $job, array $data = [], string $queue = 'default'): string
    {
        return $this->push($job, $data, $queue);
    }

    public function pop(string $queue = 'default'): ?array
    {
        return null;
    }

    public function completed(string|int $jobId): void
    {
        // Nothing to do
    }

    public function failed(string|int $jobId, string $reason): void
    {
        // Nothing to do
    }

    public function size(string $queue = 'default'): int
    {
        return 0;
    }

    public function retry(string|int $jobId): bool
    {
        return false;
    }
}
