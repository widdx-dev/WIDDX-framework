<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Minimal authorization layer: closures + policy classes.
 *
 * Two ways to answer "may this user do X?":
 *
 *   Gate::define('publish', fn($user) => $user->is_admin ?? false);
 *   if (Gate::allows('publish')) { ... }
 *
 *   App\Policies\PostPolicy::update($user, $post) — discovered BY CONVENTION:
 *   a model App\Models\Post needs no registration; just create
 *   App\Policies\PostPolicy with the action methods.
 *
 * CrudController hooks policies automatically: once a policy class exists
 * for its model, index/show/create/edit/update/destroy are guarded.
 * The user writes only the policy — still zero wiring code.
 */
class Gate
{
    /** @var array<string,callable> */
    protected static array $abilities = [];

    /** @var array<string,string> model class → policy class */
    protected static array $policies = [];

    /** Define a named ability. */
    public static function define(string $ability, callable $callback): void
    {
        static::$abilities[$ability] = $callback;
    }

    /** Bind a policy class explicitly (otherwise found by convention). */
    public static function policy(string $modelClass, string $policyClass): void
    {
        static::$policies[$modelClass] = $policyClass;
    }

    /**
     * Is there ANY guard (policy or ability) for this ability?
     * CrudController uses this to stay a no-op until a policy exists.
     */
    public static function has(string $ability, mixed $modelOrClass = null): bool
    {
        if (isset(static::$abilities[$ability])) {
            return true;
        }
        return static::policyFor($modelOrClass) !== null;
    }

    /** May the current user perform $ability? */
    public static function allows(string $ability, mixed ...$args): bool
    {
        $user = Auth::user();

        // Policy wins when a model argument has one.
        $policy = static::policyFor($args[0] ?? null);
        if ($policy !== null) {
            // Guests ARE passed through (as null): a policy declaring
            // ?User/mixed may still allow them; a strict User $user
            // signature TypeErrors, which is caught below and denied.
            $instance = new $policy();
            if (method_exists($instance, $ability)) {
                try {
                    return (bool) $instance->{$ability}($user, ...$args);
                } catch (\TypeError) {
                    return false; // malformed policy call must deny, never 500
                }
            }
            return false; // policy exists but does not allow this ability
        }

        if (isset(static::$abilities[$ability])) {
            try {
                return (bool) (static::$abilities[$ability])($user, ...$args);
            } catch (\TypeError) {
                return false;
            }
        }

        return false; // unguarded abilities must be defined before use
    }

    public static function denies(string $ability, mixed ...$args): bool
    {
        return !static::allows($ability, ...$args);
    }

    /** Resolve explicit or conventional (App\Policies\{Short}Policy) policy. */
    public static function policyFor(mixed $modelOrClass): ?string
    {
        if (is_object($modelOrClass)) {
            $modelOrClass = $modelOrClass::class;
        }
        if (!is_string($modelOrClass) || $modelOrClass === '') {
            return null;
        }
        if (isset(static::$policies[$modelOrClass]) && class_exists(static::$policies[$modelOrClass])) {
            return static::$policies[$modelOrClass];
        }
        // Convention: App\Models\Post → App\Policies\PostPolicy
        if (str_starts_with($modelOrClass, 'App\\Models\\')) {
            $short = (new \ReflectionClass($modelOrClass))->getShortName();
            $guess = 'App\\Policies\\' . $short . 'Policy';
            if (class_exists($guess)) {
                return $guess;
            }
        }
        return null;
    }

    /** Reset all gates/policies (tests). */
    public static function flush(): void
    {
        static::$abilities = [];
        static::$policies = [];
    }
}
