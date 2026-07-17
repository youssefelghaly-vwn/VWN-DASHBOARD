<?php

namespace App\Integration\Services;

use App\Integration\Models\Integration;
use App\Integration\Providers\IntegrationProvider;
use InvalidArgumentException;

/**
 * Resolves a provider key to its provider instance, using the plain config map
 * in config/integrations.php. This is the whole "plugin" mechanism — no factory
 * framework, no auto-discovery, just a keyed lookup.
 */
class IntegrationManager
{
    /** @var array<string, class-string<IntegrationProvider>> */
    private array $map;

    public function __construct()
    {
        $this->map = config('integrations.providers', []);
    }

    /** All available provider keys (for the "connect" UI). */
    public function keys(): array
    {
        return array_keys($this->map);
    }

    /** Resolve a provider by its key. */
    public function get(string $key): IntegrationProvider
    {
        if (! isset($this->map[$key])) {
            throw new InvalidArgumentException("Unknown integration provider: {$key}");
        }

        return app($this->map[$key]);
    }

    /** Resolve the provider that runs a given integration. */
    public function for(Integration $integration): IntegrationProvider
    {
        return $this->get($integration->provider);
    }

    /** ['key' => 'Label', ...] for populating menus/dropdowns. */
    public function catalogue(): array
    {
        $out = [];

        foreach ($this->keys() as $key) {
            $out[$key] = $this->get($key)->label();
        }

        return $out;
    }
}
