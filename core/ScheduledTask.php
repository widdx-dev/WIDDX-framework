<?php

declare(strict_types=1);

namespace Widdx;

/**
 * One entry in the cron schedule. Built with frequency helpers:
 *
 *   Schedule::command('queue:work --max=100')->everyMinute();
 *   Schedule::call(fn() => stats() )->daily();
 *
 * Due-ness is a TIME BUCKET comparison (integer division of now by the
 * interval) persisted in storage/app/schedule.json — no external store.
 */
class ScheduledTask
{
    protected int $intervalSeconds = 60;
    protected ?\Closure $gate = null;
    protected int $seq = 0;

    public function __construct(
        public readonly string $kind,     // 'command' | 'call'
        public readonly mixed $task,      // string command or callable
    ) {
    }

    public function everyMinute(): static
    {
        return $this->interval(60);
    }

    public function everyFiveMinutes(): static
    {
        return $this->interval(300);
    }

    public function everyTenMinutes(): static
    {
        return $this->interval(600);
    }

    public function everyFifteenMinutes(): static
    {
        return $this->interval(900);
    }

    public function everyThirtyMinutes(): static
    {
        return $this->interval(1800);
    }

    public function hourly(): static
    {
        return $this->interval(3600);
    }

    public function daily(): static
    {
        return $this->interval(86400);
    }

    public function monthly(): static
    {
        return $this->interval(2592000);
    }

    /** Run only while this closure returns true (weather, feature flag…). */
    public function when(callable $gate): static
    {
        $this->gate = \Closure::fromCallable($gate);
        return $this;
    }

    /** Internal: registration order — stable across cron processes. */
    public function setSeq(int $seq): static
    {
        $this->seq = $seq;
        return $this;
    }

    public function interval(int $seconds): static
    {
        $this->intervalSeconds = max(1, $seconds);
        return $this;
    }

    public function bucket(): int
    {
        return intdiv(time(), $this->intervalSeconds);
    }

    public function due(int $lastBucket): bool
    {
        if ($this->gate !== null && !call_user_func($this->gate)) {
            return false;
        }
        return $lastBucket < $this->bucket();
    }

    public function key(): string
    {
        $identity = $this->kind === 'command'
            ? (string) $this->task
            : (new \ReflectionFunction(\Closure::fromCallable($this->task)))->getFileName()
              . ':' . (new \ReflectionFunction(\Closure::fromCallable($this->task)))->getStartLine();
        return sha1($this->seq . '|' . $this->kind . '|' . $identity . '|' . $this->intervalSeconds);
    }
}
