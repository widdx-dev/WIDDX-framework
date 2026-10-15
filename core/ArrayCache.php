<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Contracts\CacheInterface;

/**
 * Array cache driver — for testing and development.
 * Data is lost when the request ends.
 */
class ArrayCache implements CacheInterface
{
    protected array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->items[$key])) {
            return $default;
        }
        $item = $this->items[$key];
        if ($item['expires'] !== 0 && $item['expires'] < time()) {
            unset($this->items[$key]);
            return $default;
        }
        return $item['value'];
    }

    public function put(string $key, mixed $value, int $seconds = 0): bool
    {
        $this->items[$key] = [
            'value' => $value,
            'expires' => $seconds > 0 ? time() + $seconds : 0,
        ];
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function forget(string $key): bool
    {
        if (isset($this->items[$key])) {
            unset($this->items[$key]);
            return true;
        }
        return false;
    }

    public function flush(): bool
    {
        $this->items = [];
        return true;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function increment(string $key, int $value = 1, int $ttlOnCreate = 0): int|false
    {
        $current = (int) $this->get($key, 0);
        $new = $current + $value;
        // Fixed window: keep the existing expiry; only a brand-new key takes $ttlOnCreate.
        $exists = isset($this->items[$key]);
        $expires = ($exists && (int) $this->items[$key]['expires'] !== 0)
            ? (int) $this->items[$key]['expires']
            : ($ttlOnCreate > 0 ? time() + $ttlOnCreate : 0);
        $this->items[$key] = ['value' => $new, 'expires' => $expires];
        return $new;
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->increment($key, -$value);
    }
}
