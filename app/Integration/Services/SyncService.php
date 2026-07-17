<?php

namespace App\Integration\Services;

use App\Integration\Models\Integration;
use App\Integration\Models\SyncRun;

/**
 * Drives a sync for any integration: opens a SyncRun, hands the provider a
 * SyncContext to write into, then closes the run with the counts and errors the
 * context gathered. This is the only orchestration layer — providers stay
 * focused purely on fetching + shaping their own data.
 */
class SyncService
{
    public function run(Integration $integration): SyncRun
    {
        $run = $integration->syncRuns()->create([
            'status' => SyncRun::RUNNING,
            'started_at' => now(),
        ]);

        $context = new SyncContext($integration);

        try {
            $integration->provider()->sync($integration, $context);
            $status = $context->failedRecords() > 0 ? SyncRun::PARTIAL : SyncRun::SUCCESS;
        } catch (\Throwable $e) {
            $context->fail('_sync', $e->getMessage());
            $status = SyncRun::FAILED;
        }

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'records_synced' => $context->recordsSynced(),
            'failed_records' => $context->failedRecords(),
            'last_error' => $context->lastError(),
            'meta' => ['datasets' => $context->datasets()],
        ]);

        $integration->update([
            'last_synced_at' => now(),
            'status' => $status === SyncRun::FAILED ? 'error' : 'connected',
        ]);

        return $run;
    }
}
