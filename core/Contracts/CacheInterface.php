<?php

declare(strict_types=1);

namespace Widdx\Contracts;

/**
 * Cache contract — any cache driver must implement this.
 *
 * Example drivers: FileCache, RedisCache, MemcachedCache, ArrayCache (testing)
 */
interface CacheInterface
{
    /** Get a value from cache */
    public function get(string $key, mixed $default = null): mixed;

    /** Store a value in cache (seconds = 0 means forever) */
    public function put(string $key, mixed $value, int $seconds = 0): bool;

    /** Check if key exists */
    public function has(string $key): bool;

    /** Remove a key */
    public function forget(string $key): bool;

    /** Remove all cache */
    public function flush(): bool;

    /** Get and delete in one call */
    public function pull(string $key, mixed $default = null): mixed;

    /** Increment a numeric value */
    public function increment(string $key, int $value = 1, int $ttlOnCreate = 0): int|false;

    /** Decrement a numeric value */
    public function decrement(string $key, int $value = 1): int|false;
}
