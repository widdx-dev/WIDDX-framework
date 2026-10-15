<?php

declare(strict_types=1);

namespace Widdx\Tests;

use Widdx\Model;

/** 1.6 guard fixture: a NON-relation method with side effects + no return type. */
class Jargon extends Model
{
    protected ?string $table = 'leaks';

    protected array $fillable = ['name'];

    public bool $touched = false;

    public function jargon()
    {
        $this->touched = true;
        return 'CALLED';
    }
}
