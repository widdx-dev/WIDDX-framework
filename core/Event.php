<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Event system: simple observer pattern (all methods are static).
 *
 * Usage:
 *   Event::listen('user.created', function($user) { ... });
 *   Event::fire('user.created', $user);
 *
 * EventInterface documents the driver contract for custom dispatchers
 * implemented as instance methods.
 */
class Event
{
    /** @var array<string, array<callable>> */
    protected static array $listeners = [];

    /** @var list<array{event:string, args:array}> Queued events for later dispatch */
    protected static array $queued = [];

    /**
     * Register an event listener.
     * @param string $event Event name
     * @param callable $listener Callback
     */
    public static function listen(string $event, callable $listener): void
    {
        static::$listeners[$event][] = $listener;
    }

    /**
     * Fire an event and call all listeners.
     * @param string $event Event name
     * @param mixed ...$args Arguments to pass to listeners
     * @return array Results from all listeners
     */
    public static function fire(string $event, mixed ...$args): array
    {
        $results = [];
        foreach (static::$listeners[$event] ?? [] as $listener) {
            $results[] = $listener(...$args);
        }
        return $results;
    }

    /**
     * Fire an event and halt on first non-null response.
     * Useful for middleware-like patterns.
     */
    public static function until(string $event, mixed ...$args): mixed
    {
        foreach (static::$listeners[$event] ?? [] as $listener) {
            $result = $listener(...$args);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Queue an event for later dispatch (after response is sent).
     */
    public static function queue(string $event, mixed ...$args): void
    {
        static::$queued[] = ['event' => $event, 'args' => $args];
    }

    /** Maximum generations of events dispatched by one flush. Prevents a listener that
     * keeps re-queueing itself from hanging a worker indefinitely. */
    protected const MAX_FLUSH_ROUNDS = 100;

    /**
     * Dispatch queued events after the response has been sent.
     *
     * Each batch is removed before dispatch; events enqueued by listeners are
     * then processed in the next round during this same flush. Listener errors
     * are isolated individually, so one broken subscriber cannot prevent other
     * subscribers or queued events from running.
     */
    public static function flush(): void
    {
        $round = 0;
        while (static::$queued !== [] && $round < self::MAX_FLUSH_ROUNDS) {
            $batch = static::$queued;
            static::$queued = [];
            $round++;

            foreach ($batch as $q) {
                $event = (string) ($q['event'] ?? 'unknown');
                foreach (static::$listeners[$event] ?? [] as $listener) {
                    try {
                        $listener(...($q['args'] ?? []));
                    } catch (\Throwable $e) {
                        static::logQueuedFailure($event, $e);
                    }
                }
            }
        }

        // A pathological listener chain may enqueue forever. Drop the remaining
        // in-memory work after the cap and report it, rather than hanging PHP-FPM.
        if (static::$queued !== []) {
            $remaining = count(static::$queued);
            static::$queued = [];
            try {
                logger('error', 'Queued event flush limit reached; remaining events discarded.', [
                    'round_limit' => self::MAX_FLUSH_ROUNDS,
                    'discarded_events' => $remaining,
                ]);
            } catch (\Throwable) {
                error_log('Widdx queued event flush limit reached; remaining events discarded: ' . $remaining);
            }
        }
    }

    /** Log a queued-listener failure without its arguments, which may contain secrets or PII. */
    protected static function logQueuedFailure(string $event, \Throwable $e): void
    {
        try {
            logger('error', 'Queued event listener failed.', [
                'event' => $event,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            error_log('Widdx queued event listener failed: event=' . $event . ' exception=' . get_class($e));
        }
    }

    /**
     * Remove all listeners for an event (or all events).
     */
    public static function forget(?string $event = null): void
    {
        if ($event === null) {
            static::$listeners = [];
        } else {
            unset(static::$listeners[$event]);
        }
    }

    /**
     * Get all registered listeners for debugging.
     */
    public static function getListeners(?string $event = null): array
    {
        if ($event === null) {
            return static::$listeners;
        }
        return static::$listeners[$event] ?? [];
    }
}
