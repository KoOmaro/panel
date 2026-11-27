<?php

namespace App\Support\Integrations;

use App\Support\Integrations\Hetzner\HetznerProvider;
use Illuminate\Container\Attributes\Singleton;
use InvalidArgumentException;

#[Singleton]
final class IntegrationProviders
{
    /** @var array<string, Provider> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new HetznerProvider);
    }

    public function register(Provider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function find(string $key): ?Provider
    {
        return $this->providers[$key] ?? null;
    }

    public function get(string $key): Provider
    {
        return $this->providers[$key] ?? throw new InvalidArgumentException("No integration provider is built for {$key}.");
    }

    /** @return array<string, Provider> */
    public function all(): array
    {
        return $this->providers;
    }
}
