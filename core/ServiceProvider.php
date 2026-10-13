<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Service Provider base class.
 *
 * Service providers are the recommended way to register bindings,
 * event listeners, and boot-time logic.
 *
 * Example:
 *   class DatabaseServiceProvider extends ServiceProvider
 *   {
 *       public function register(): void
 *       {
 *           $this->app->singleton(Database::class, fn() => new Database(config('database')));
 *       }
 *
 *       public function boot(): void
 *       {
 *           // Runs after all providers are registered
 *       }
 *   }
 */
abstract class ServiceProvider
{
    protected Container $app;

    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    /**
     * Register bindings in the container.
     * Called before boot().
     */
    abstract public function register(): void;

    /**
     * Boot the provider.
     * Called after all providers are registered.
     */
    public function boot(): void
    {
        // Override in child classes
    }
}
