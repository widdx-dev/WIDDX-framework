<?php

declare(strict_types=1);

namespace Widdx\Tests;

use Widdx\Model;

/** Auth-test fixture (table: auth_users). */
class AuthUser extends Model
{
    protected array $fillable = ['name', 'email', 'password', 'email_verified_at'];

    protected array $hidden = ['password'];
}
