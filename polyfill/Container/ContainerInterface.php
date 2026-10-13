<?php

declare(strict_types=1);

/**
 * Minimal offline stand-in for the psr/container package (v2).
 *
 * Loaded ONLY by the no-Composer fallback autoloaders (public/index.php,
 * widdx, widdx-test) which map the 'Psr\' prefix to this directory.
 * When Composer is installed, the real psr/container package is
 * autoloaded first and this file is never used.
 */

namespace Psr\Container;

interface ContainerInterface
{
    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @return mixed Identifier of the entry to look for.
     */
    public function get(string $id);

    /**
     * Returns true if the container can return an entry for the given identifier.
     */
    public function has(string $id): bool;
}
