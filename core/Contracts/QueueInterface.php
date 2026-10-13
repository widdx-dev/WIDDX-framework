<?php

declare(strict_types=1);

namespace Widdx\Contracts;

/**
 * Queue contract — any queue driver must implement this.
 *
 * Example drivers: DatabaseQueue, RedisQueue, SyncQueue (immediate execution)
 */
interface QueueInterface
{
    /** Push a job onto the queue */
    public function push(string $job, array $data = [], string $queue = 'default'): string;

    /** Push a job with delay (seconds) */
    public function later(int $delay, string $job, array $data = [], string $queue = 'default'): string;

    /** Pop the next job from the queue */
    public function pop(string $queue = 'default'): ?array;

    /** Mark job as completed */
    public function completed(string|int $jobId): void;

    /** Mark job as failed */
    public function failed(string|int $jobId, string $reason): void;

    /** Get queue size */
    public function size(string $queue = 'default'): int;

    /** Retry a failed job */
    public function retry(string|int $jobId): bool;
}
