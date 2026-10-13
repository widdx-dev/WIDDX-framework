<?php

declare(strict_types=1);

namespace Widdx\Tests;

/** Policy fixture: open to view, closed to delete. */
class TeamPolicy
{
    public function viewAny(mixed $user, mixed $team = null): bool
    {
        return true;
    }

    public function delete(mixed $user, mixed $team = null): bool
    {
        return false;
    }
}
