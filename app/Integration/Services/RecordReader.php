<?php

namespace App\Integration\Services;

use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;

/**
 * Reads synced local rows for a given integration + dataset. This is the ONLY
 * way dashboards and metrics reach data — they never call an external API. Rows
 * are memoized per request so a dashboard with many widgets over the same
 * dataset hits the database once.
 */
class RecordReader
{
    /** @var array<string, array> */
    private array $cache = [];

    public function rows(?int $integrationId, string $dataset): array
    {
        if (! $integrationId || $dataset === '') {
            return [];
        }

        $key = $integrationId.':'.$dataset;

        return $this->cache[$key] ??= IntegrationRecord::query()
            ->where('integration_id', $integrationId)
            ->where('dataset', $dataset)
            ->get()
            ->map(fn (IntegrationRecord $r) => $r->payload)
            ->all();
    }

    /** Column names available in a dataset (drives builder dropdowns / tables). */
    public function columns(?int $integrationId, string $dataset): array
    {
        $rows = $this->rows($integrationId, $dataset);

        $cols = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $k) {
                $cols[$k] = true;
            }
        }

        return array_keys($cols);
    }

    /** Full schema across every connected integration, for the builder. */
    public function schema(): array
    {
        $out = [];

        foreach (Integration::where('status', 'connected')->get() as $integration) {
            $datasets = $integration->provider()->schema($integration);

            foreach ($datasets as $dataset => $columns) {
                $out[] = [
                    'integration_id' => $integration->id,
                    'integration' => $integration->name,
                    'provider' => $integration->provider,
                    'dataset' => $dataset,
                    'columns' => $columns,
                ];
            }
        }

        return $out;
    }
}
