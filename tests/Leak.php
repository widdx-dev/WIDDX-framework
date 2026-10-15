<?php

declare(strict_types=1);

namespace Widdx\Tests;

use Widdx\Model;

/** Mass-assignment test fixture: NO $fillable → must deny everything. */
class Leak extends Model
{
    protected ?string $table = 'leaks';
}
