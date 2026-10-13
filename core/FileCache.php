<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Contracts\CacheInterface;

/**
 * File-based cache driver.
 *
 * Stores cache as serialized files in storage/cache/.
 * Supports TTL (time-to-live) in seconds.
 */
class FileCache implements CacheInterface
{
    protected string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (dirname(__DIR__) . '/storage/cache');
        if (!is_dir($this->path)) {
            mkdir($this->path, 0777, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        $data = $raw === false ? false : @unserialize($raw);
        // Corrupted or unreadable cache file: drop it and treat as a miss.
        if (!is_array($data) || !array_key_exists('value', $data) || !array_key_exists('expires', $data)) {
            @unlink($file);
            return $default;
        }
        if ($data['expires'] !== 0 && $data['expires'] < time()) {
            @unlink($file);
            return $default;
        }
        return $data['value'];
    }

    public function put(string $key, mixed $value, int $seconds = 0): bool
    {
        $file = $this->file($key);
        $data = [
            'value' => $value,
            'expires' => $seconds > 0 ? time() + $seconds : 0,
        ];
        return file_put_contents($file, serialize($data)) !== false;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function forget(string $key): bool
    {
        $file = $this->file($key);
        if (is_file($file)) {
            return unlink($file);
        }
        return false;
    }

    public function flush(): bool
    {
        foreach (glob($this->path . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        return true;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function increment(string $key, int $value = 1): int|false
    {
        $current = (int) $this->get($key, 0);
        $new = $current + $value;
        $this->put($key, $new);
        return $new;
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->increment($key, -$value);
    }

    protected function file(string $key): string
    {
        return $this->path . '/' . md5($key) . '.cache';
    }
}
