<?php

declare(strict_types=1);

namespace Widdx\Concerns;

use Widdx\Auth;
use Widdx\Gate;
use Widdx\Router;

/**
 * Shared fail-closed authorization for CrudController + ApiCrudController.
 *
 * Deny is the DEFAULT. An action is allowed only when ONE of these holds:
 *   1. a policy/ability exists for it AND allows the current user;
 *   2. the route group was marked ->public()  (e.g. resource('posts')->public());
 *   3. the controller sets protected bool $public = true.
 *
 * A guest hitting a denied action never sees a 500: policies are simply not
 * invoked with null (TypeError from strict signatures is caught too, treated
 * as deny).
 */
trait GatesCrudActions
{
    /** Explicit controller-level opt-out of the authorization gate. */
    protected bool $public = false;

    /**
     * May the current user perform $ability on $target?
     * Policy verdict always wins — even over ->public().
     */
    protected function crudAuthorized(string $ability, mixed $target): bool
    {
        if (Gate::has($ability, $target)) {
            return Gate::allows($ability, $target);
        }
        if ($this->public === true) {
            return true;
        }
        return Router::getInstance()->currentRouteIsPublic();
    }

    /** Guests are never silently allowed anywhere. */
    protected function crudIsGuest(): bool
    {
        return Auth::user() === null;
    }
}
