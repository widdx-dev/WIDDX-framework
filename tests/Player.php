<?php

declare(strict_types=1);

namespace Widdx\Tests;

use Widdx\Model;

/** Relation-test fixture: Player belongsTo Team (team_id inferred). */
class Player extends Model
{
    protected array $fillable = ['name', 'team_id'];

    public function team(): \Widdx\Relations\BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
