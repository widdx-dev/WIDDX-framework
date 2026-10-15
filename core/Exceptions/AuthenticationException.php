<?php

declare(strict_types=1);

namespace Widdx\Exceptions;

/**
 * Thrown at the fail-closed CRUD gate when a GUEST hits a guarded action.
 * App::renderException turns it into a 302 to /login (web) — never a 500.
 */
class AuthenticationException extends WiddxException {}
