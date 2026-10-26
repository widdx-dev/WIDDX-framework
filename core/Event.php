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

    /**
     * Dispatch queued events without allowing a failed post-response listener
     * to corrupt the already-sent HTTP response or prevent later events.
     *
     * The current batch is removed before dispatch. Events queued by a listener
     * are retained for the next flush instead of being accidentally discarded.
     */
    public static function flush(): void
    {
        $batch = static::$queued;
        static::$queued = [];

        foreach ($batch as $q) {
            try {
                static::fire($q['event'], ...$q['args']);
            } catch (\Throwable $e) {
                // Queued work runs after a response has been sent, so bubbling
                // this exception can append an error page to a successful body.
                // Log without event arguments; they may contain tokens or PII.
                try {
                    logger('error', 'Queued event listener failed.', [
                        'event' => (string) ($q['event'] ?? 'unknown'),
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {
                    error_log('Widdx queued event listener failed: event='
                        . (string) ($q['event'] ?? 'unknown') . ' exception=' . get_class($e));
                }
            }
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
