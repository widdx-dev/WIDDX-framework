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
            mkdir($this->path, 0775, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        $data = $raw === false ? false : @unserialize($raw, ['allowed_classes' => false]);
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
        // 2.10: write to a temp file then rename, so a concurrent reader only
        // ever sees a complete (never half-written) cache entry.
        return $this->writeAtomic($file, serialize($data));
    }

    protected function writeAtomic(string $file, string $payload): bool
    {
        $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
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

    public function increment(string $key, int $value = 1, int $ttlOnCreate = 0): int|false
    {
        $file = $this->file($key);
        // 2.10: read-modify-write under an exclusive lock → atomic counter.
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return false;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }
        $raw = stream_get_contents($fp);
        $current = 0;
        $expires = $ttlOnCreate > 0 ? time() + $ttlOnCreate : 0;
        if (is_string($raw) && $raw !== '') {
            $data = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($data) && array_key_exists('value', $data) && array_key_exists('expires', $data)) {
                $expired = $data['expires'] !== 0 && $data['expires'] < time();
                if (!$expired) {
                    $current = (int) $data['value'];
                    $expires = (int) $data['expires']; // fixed window: keep expiry
                }
            }
        }
        $new = $current + $value;
        $payload = serialize(['value' => $new, 'expires' => $expires]);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $payload);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        @chmod($file, 0640);
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
