<?php

declare(strict_types=1);

namespace Widdx\Tests;

use Widdx\Model;

/** Relation-test fixture: inverse of Role↔Permission. */
class Permission extends Model
{
    protected array $fillable = ['name'];

    public function roles(): \Widdx\Relations\BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
