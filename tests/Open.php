<?php

declare(strict_types=1);

namespace Widdx\Tests;

use Widdx\Model;

/** Mass-assignment test fixture: $fillable = ['*'] → explicit allow-all opt-out. */
class Open extends Model
{
    protected ?string $table = 'leaks';

    protected array $fillable = ['*'];
}
