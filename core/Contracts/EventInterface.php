<?php

declare(strict_types=1);

namespace Widdx\Contracts;

/**
 * Event contract — any event dispatcher must implement this.
 */
interface EventInterface
{
    /** Register a listener */
    public function listen(string $event, callable $listener): void;

    /** Fire an event */
    public function fire(string $event, mixed ...$args): array;

    /** Fire until first non-null response */
    public function until(string $event, mixed ...$args): mixed;

    /** Queue an event for later */
    public function queue(string $event, mixed ...$args): void;

    /** Dispatch queued events */
    public function flush(): void;

    /** Remove listeners */
    public function forget(?string $event = null): void;
}
