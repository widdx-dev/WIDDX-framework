<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Contracts\QueueInterface;

/**
 * Queue manager — facade for queue operations.
 *
 * Usage:
 *   Queue::push(SendEmail::class, ['to' => 'user@example.com']);
 *   Queue::later(60, SendEmail::class, ['to' => 'user@example.com']);
 *   Queue::pop(); // process next job
 */
class Queue
{
    protected static ?QueueInterface $instance = null;

    /**
     * Get queue instance (respects container binding if registered).
     */
    public static function connection(): QueueInterface
    {
        if (static::$instance === null) {
            // Prefer the container binding so drivers are swappable at runtime
            $container = Container::getInstance();
            if ($container->has(QueueInterface::class)) {
                return static::$instance = $container->get(QueueInterface::class);
            }
            $driver = (string) (config('queue.default') ?? 'sync');
            static::$instance = $driver === 'database' ? new DatabaseQueue() : new SyncQueue();
        }
        return static::$instance;
    }

    /** Set custom queue instance */
    public static function setConnection(QueueInterface $queue): void
    {
        static::$instance = $queue;
    }

    /** Push a job (job class must be instantiable) */
    public static function push(string $job, array $data = [], string $queue = 'default'): string
    {
        return static::connection()->push($job, $data, $queue);
    }

    /** Push a job with delay in seconds */
    public static function later(int $delay, string $job, array $data = [], string $queue = 'default'): string
    {
        return static::connection()->later($delay, $job, $data, $queue);
    }

    /** Pop next available job */
    public static function pop(string $queue = 'default'): ?array
    {
        return static::connection()->pop($queue);
    }

    /** Number of pending jobs */
    public static function size(string $queue = 'default'): int
    {
        return static::connection()->size($queue);
    }

    /** Retry a failed job by id */
    public static function retry(string $jobId): bool
    {
        return static::connection()->retry($jobId);
    }

    /**
     * Process pending jobs.
     * Returns number of processed jobs.
     */
    public static function work(string $queue = 'default', int $maxJobs = 0): int
    {
        $connection = static::connection();
        $processed = 0;

        while (true) {
            if ($maxJobs > 0 && $processed >= $maxJobs) {
                break;
            }

            $job = $connection->pop($queue);
            if ($job === null) {
                break;
            }

            try {
                $payload = $job['payload'];
                $class = $payload['job'] ?? null;
                if (!is_string($class) || !class_exists($class)) {
                    throw new Exceptions\QueueException("Job class [{$class}] does not exist.");
                }
                (new $class())->handle($payload['data'] ?? []);
                $connection->completed($job['id']);
            } catch (\Throwable $e) {
                $connection->failed($job['id'], $e->getMessage());
                logger('error', "Queue job failed: {$e->getMessage()}");
            }

            $processed++;
        }

        return $processed;
    }
}
