<?php

declare(strict_types=1);

namespace Widdx\Contracts;

/**
 * Storage contract — any filesystem driver must implement this.
 *
 * Shipped driver: LocalStorage
 */
interface StorageInterface
{
    /** Store a file */
    public function put(string $path, string $contents, array $options = []): bool;

    /** Get file contents */
    public function get(string $path): ?string;

    /** Check if file exists */
    public function exists(string $path): bool;

    /** Delete a file */
    public function delete(string $path): bool;

    /** Get file size in bytes */
    public function size(string $path): int;

    /** Get file modification time */
    public function lastModified(string $path): int;

    /** Copy a file */
    public function copy(string $from, string $to): bool;

    /** Move a file */
    public function move(string $from, string $to): bool;

    /** List files in directory */
    public function files(string $directory = ''): array;

    /** Create directory */
    public function makeDirectory(string $path): bool;

    /** Delete directory */
    public function deleteDirectory(string $path): bool;

    /** Get public URL */
    public function url(string $path): string;
}
