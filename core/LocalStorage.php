<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Contracts\StorageInterface;

/**
 * Local filesystem storage driver.
 */
class LocalStorage implements StorageInterface
{
    protected string $root;
    protected string $url;

    public function __construct(?string $root = null, string $url = '/storage')
    {
        $this->root = $root ?? (dirname(__DIR__) . '/storage/app');
        $this->url = rtrim($url, '/');
        if (!is_dir($this->root)) {
            mkdir($this->root, 0777, true);
        }
    }

    public function put(string $path, string $contents, array $options = []): bool
    {
        $file = $this->fullPath($path);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return file_put_contents($file, $contents) !== false;
    }

    public function get(string $path): ?string
    {
        $file = $this->fullPath($path);
        if (!is_file($file)) {
            return null;
        }
        return file_get_contents($file);
    }

    public function exists(string $path): bool
    {
        return is_file($this->fullPath($path));
    }

    public function delete(string $path): bool
    {
        $file = $this->fullPath($path);
        if (is_file($file)) {
            return unlink($file);
        }
        return false;
    }

    public function size(string $path): int
    {
        $file = $this->fullPath($path);
        return is_file($file) ? filesize($file) : 0;
    }

    public function lastModified(string $path): int
    {
        $file = $this->fullPath($path);
        return is_file($file) ? filemtime($file) : 0;
    }

    public function copy(string $from, string $to): bool
    {
        return copy($this->fullPath($from), $this->fullPath($to));
    }

    public function move(string $from, string $to): bool
    {
        return rename($this->fullPath($from), $this->fullPath($to));
    }

    public function files(string $directory = ''): array
    {
        $dir = $this->fullPath($directory);
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                $files[] = str_replace($this->root . '/', '', $file);
            }
        }
        return $files;
    }

    public function makeDirectory(string $path): bool
    {
        $dir = $this->fullPath($path);
        if (is_dir($dir)) {
            return true;
        }
        return mkdir($dir, 0777, true);
    }

    public function deleteDirectory(string $path): bool
    {
        $dir = $this->fullPath($path);
        if (!is_dir($dir)) {
            return false;
        }
        foreach (glob($dir . '/*') ?: [] as $item) {
            is_dir($item) ? $this->deleteDirectory(str_replace($this->root . '/', '', $item)) : unlink($item);
        }
        return rmdir($dir);
    }

    public function url(string $path): string
    {
        return $this->url . '/' . ltrim($path, '/');
    }

    /** Absolute root of this disk (used by UploadedFile::store()). */
    public function root(): string
    {
        return $this->root;
    }

    protected function fullPath(string $path): string
    {
        return $this->root . '/' . ltrim($path, '/');
    }
}
