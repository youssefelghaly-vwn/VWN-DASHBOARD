<?php

namespace App\Integration\Models;

use App\Integration\Providers\IntegrationProvider;
use App\Integration\Services\IntegrationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single connected integration instance — one row per connection (e.g. one
 * GoHighLevel location, one Google Sheet, one Meta ad account owner).
 *
 * The model is provider-agnostic: `provider` says which provider class runs it,
 * `credentials` holds whatever that provider needs to authenticate (encrypted),
 * and `config` holds non-secret per-instance options (which datasets to pull,
 * endpoint URLs, watermarks, …). Nothing here is GoHighLevel-specific.
 */
class Integration extends Model
{
    protected $fillable = [
        'provider', 'name', 'credentials', 'config', 'status', 'last_synced_at',
    ];

    protected $casts = [
        // Secrets (tokens, keys) are encrypted at rest.
        'credentials' => 'encrypted:array',
        'config' => 'array',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = ['credentials'];

    protected $attributes = [
        'status' => 'disconnected',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(IntegrationRecord::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function latestSyncRun(): HasOne
    {
        return $this->hasOne(SyncRun::class)->latestOfMany();
    }

    /** Resolve the provider that runs this integration. */
    public function provider(): IntegrationProvider
    {
        return app(IntegrationManager::class)->for($this);
    }

    /** Read one credential value without exposing the whole encrypted array. */
    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
    }

    /** Read one config value. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /** Merge values into the config JSON and persist. */
    public function updateConfig(array $values): void
    {
        $this->update(['config' => array_merge($this->config ?? [], $values)]);
    }

    /** Rows already synced for one dataset, as plain arrays for the aggregators. */
    public function rows(string $dataset): array
    {
        return $this->records()
            ->where('dataset', $dataset)
            ->get()
            ->map(fn (IntegrationRecord $r) => $r->payload)
            ->all();
    }
}
