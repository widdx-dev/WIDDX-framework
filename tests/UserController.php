<?php

declare(strict_types=1);

namespace Widdx\Tests;

/**
 * Zero-code fixture: an EMPTY controller class must still resolve
 * App\Models\User purely from its name.
 */
class UserController extends \Widdx\CrudController
{
}
