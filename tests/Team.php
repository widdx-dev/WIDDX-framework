<?php

declare(strict_types=1);

namespace Widdx\Tests;

use Widdx\Model;

/** Relation-test fixture: Team hasMany Player (players.team_id inferred). */
class Team extends Model
{
    protected array $fillable = ['name'];

    public function players()
    {
        return $this->hasMany(Player::class);
    }

    public function captain()
    {
        return $this->hasOne(Player::class);
    }
}
