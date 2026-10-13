<?php

declare(strict_types=1);

namespace Widdx\Contracts;

/**
 * Session contract — any session driver must implement this.
 *
 * Example drivers: FileSession, DatabaseSession, RedisSession, ArraySession (testing)
 */
interface SessionInterface
{
    /** Start the session */
    public function start(): void;

    /** Get a value */
    public function get(string $key, mixed $default = null): mixed;

    /** Store a value */
    public function put(string $key, mixed $value): void;

    /** Check existence */
    public function has(string $key): bool;

    /** Remove a value */
    public function forget(string $key): void;

    /** Remove all data */
    public function flush(): void;

    /** Flash data (available on next request only) */
    public function flash(string $key, mixed $value): void;

    /** Get all data */
    public function all(): array;

    /** Regenerate session ID */
    public function regenerate(): void;

    /** Destroy session */
    public function destroy(): void;
}
