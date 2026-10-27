<?php

declare(strict_types=1);

namespace App\Models;

use Widdx\Model;

/**
 * User model.
 *
 * - Table 'users' inferred automatically.
 * - $rules auto-validated by create() — validation failure redirects
 *   back with errors + old input, with zero controller code.
 */
class User extends Model
{
    protected array $fillable = ['name', 'email'];

    protected array $hidden = ['password'];

    protected array $rules = [
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
    ];
}
