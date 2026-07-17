<?php

namespace App\DataHealth\Services;

use App\Integration\Models\Integration;

/**
 * Builds the generic Data Health report: one uniform row per integration,
 * derived entirely from the integration + its latest SyncRun. No provider knows
 * about this class, and this class knows nothing provider-specific — every
 * integration reports Last Sync, Status, Records Synced, Failed Records, and
 * Last Error the same way.
 */
class DataHealthService
{
    public function report(): array
    {
        return Integration::query()
            ->with('latestSyncRun')
            ->orderBy('name')
            ->get()
            ->map(function (Integration $integration) {
                $run = $integration->latestSyncRun;

                return [
                    'id' => $integration->id,
                    'name' => $integration->name,
                    'provider' => $integration->provider,
                    'status' => $run?->status ?? $integration->status,
                    'last_sync' => $integration->last_synced_at?->diffForHumans(),
                    'last_sync_at' => $integration->last_synced_at?->toDayDateTimeString(),
                    'records_synced' => $run?->records_synced ?? 0,
                    'failed_records' => $run?->failed_records ?? 0,
                    'last_error' => $run?->last_error,
                    'datasets' => data_get($run?->meta, 'datasets', []),
                    'duration_ms' => $run?->durationMs(),
                ];
            })
            ->all();
    }
}
