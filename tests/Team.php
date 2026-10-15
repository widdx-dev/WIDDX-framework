<?php

declare(strict_types=1);

namespace Widdx\Tests;

use Widdx\Model;

/** Relation-test fixture: Team hasMany Player (players.team_id inferred). */
class Team extends Model
{
    protected array $fillable = ['name'];

    public function players(): \Widdx\Relations\HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function captain(): \Widdx\Relations\HasOne
    {
        return $this->hasOne(Player::class);
    }
}
