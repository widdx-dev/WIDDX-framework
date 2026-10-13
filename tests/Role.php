<?php

declare(strict_types=1);

namespace Widdx\Tests;

use Widdx\Model;

/** Relation-test fixture: Role belongsToMany Permission (pivot permission_role). */
class Role extends Model
{
    protected array $fillable = ['name'];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }
}
