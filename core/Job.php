<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Job base class.
 *
 * Extend this to create queue jobs:
 *
 *   class SendWelcomeEmail extends Job
 *   {
 *       public function handle(array $data): void
 *       {
 *           // Send email...
 *       }
 *   }
 *
 * Dispatch:
 *   SendWelcomeEmail::dispatch(['user_id' => 5]);
 *   SendWelcomeEmail::dispatch(['user_id' => 5])->delay(60);
 */
abstract class Job
{
    /** Delay in seconds */
    protected int $delay = 0;

    /** Queue name */
    protected string $queue = 'default';

    /** Max retry attempts (1 = no retry) */
    protected int $tries = 3;

    /** Backoff seconds between attempts (doubles each retry) */
    protected int $backoff = 5;

    /** Execute the job */
    abstract public function handle(array $data): void;

    /** Read by the queue driver when embedding payloads. */
    public function getTries(): int
    {
        return max(1, $this->tries);
    }

    public function getBackoff(): int
    {
        return max(0, $this->backoff);
    }

    public function getQueueName(): string
    {
        return $this->queue;
    }

    /** Dispatch the job to the queue */
    public static function dispatch(array $data = []): string
    {
        $job = new static();
        return $job->push($data);
    }

    /** Dispatch with delay */
    public static function dispatchLater(int $delay, array $data = []): string
    {
        $job = new static();
        return $job->push($data, $delay);
    }

    /** Push this instance onto the queue */
    public function push(array $data = [], ?int $delay = null): string
    {
        $delay ??= $this->delay;
        return $delay > 0
            ? Queue::later($delay, static::class, $data, $this->queue)
            : Queue::push(static::class, $data, $this->queue);
    }

    /** Set delay in seconds (chainable) */
    public function delay(int $seconds): static
    {
        $this->delay = $seconds;
        return $this;
    }

    /** Set queue name (chainable) */
    public function onQueue(string $queue): static
    {
        $this->queue = $queue;
        return $this;
    }

    /** Set max attempts (chainable) */
    public function tries(int $tries): static
    {
        $this->tries = $tries;
        return $this;
    }
}
