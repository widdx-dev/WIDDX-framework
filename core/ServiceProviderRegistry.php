<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Service provider registry.
 *
 * Manages registration and booting of service providers.
 *
 * Usage:
 *   $providers = new ServiceProviderRegistry($container);
 *   $providers->register(DatabaseServiceProvider::class);
 *   $providers->register(CacheServiceProvider::class);
 *   $providers->boot();
 */
class ServiceProviderRegistry
{
    protected Container $app;
    protected array $providers = [];
    protected array $registered = [];

    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    /**
     * Register a service provider class.
     */
    public function register(string $providerClass): void
    {
        if (isset($this->registered[$providerClass])) {
            return;
        }
        $provider = new $providerClass($this->app);
        $provider->register();
        $this->providers[] = $provider;
        $this->registered[$providerClass] = true;
    }

    /**
     * Boot all registered providers.
     */
    public function boot(): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot();
        }
    }

    /**
     * Get all registered provider instances.
     */
    public function providers(): array
    {
        return $this->providers;
    }
}
