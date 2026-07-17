<?php

namespace App\Integration\Services;

use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handed to a provider's sync(). It is the ONE place synced data is written to
 * the local database, and it accumulates the per-dataset counts and errors that
 * become the SyncRun / Data Health record.
 *
 * A provider fetches a dataset, then calls write() with the normalized rows.
 * Anything that throws while fetching a dataset should be reported with fail()
 * so one broken dataset degrades to "0 records + last error" instead of killing
 * the whole sync.
 */
class SyncContext
{
    private int $recordsSynced = 0;

    private int $failedRecords = 0;

    /** ['Dataset' => ['ok'=>bool,'count'=>int,'ms'=>int,'error'=>?string]] */
    private array $datasets = [];

    private ?string $lastError = null;

    public function __construct(public readonly Integration $integration) {}

    /**
     * Replace the local rows for one dataset with a fresh set. Each row is
     * ['external_id' => string|null, 'payload' => array, 'record_date' => ?string].
     * The write is transactional per dataset so a mid-write failure never leaves
     * the dataset half-updated.
     */
    public function write(string $dataset, array $rows): void
    {
        $startedAt = hrtime(true);

        DB::transaction(function () use ($dataset, $rows) {
            $this->integration->records()->where('dataset', $dataset)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                $now = now();
                $data = array_map(fn (array $row) => [
                    'integration_id' => $this->integration->id,
                    'dataset' => $dataset,
                    'external_id' => $row['external_id'] ?? null,
                    'payload' => json_encode($row['payload'] ?? $row),
                    'record_date' => $row['record_date'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk);

                IntegrationRecord::insert($data);
            }
        });

        $count = count($rows);
        $this->recordsSynced += $count;

        $this->datasets[$dataset] = [
            'ok' => true,
            'count' => $count,
            'ms' => (int) round((hrtime(true) - $startedAt) / 1e6),
            'error' => null,
        ];
    }

    /** Record that a dataset failed to sync, keeping any previously synced rows. */
    public function fail(string $dataset, string $error): void
    {
        Log::warning("Integration sync failed for {$dataset}", [
            'integration' => $this->integration->id,
            'provider' => $this->integration->provider,
            'error' => $error,
        ]);

        $this->failedRecords++;
        $this->lastError = $error;

        $this->datasets[$dataset] = [
            'ok' => false,
            'count' => 0,
            'ms' => 0,
            'error' => $error,
        ];
    }

    public function recordsSynced(): int
    {
        return $this->recordsSynced;
    }

    public function failedRecords(): int
    {
        return $this->failedRecords;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** Per-dataset breakdown, stored in SyncRun.meta for the health drill-down. */
    public function datasets(): array
    {
        return $this->datasets;
    }
}
