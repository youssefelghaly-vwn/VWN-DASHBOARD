<?php

namespace App\Integration\Jobs;

use App\Dashboard\Models\LoopStatistic;
use App\Dashboard\Services\LoopExpander;
use App\Integration\Models\Integration;
use App\Integration\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The one job that syncs any integration. It doesn't know or care which provider
 * runs — it just resolves the integration and lets the SyncService drive.
 *
 * Syncs run OUT of the request cycle because a full pull (thousands of contacts,
 * calendar events, ad insights) far exceeds any HTTP timeout. Dashboards read
 * only the local rows the last run wrote. ShouldBeUnique stops overlapping syncs
 * of the same integration from stampeding an external API.
 *
 * After the rows land, loops over this integration are re-expanded if their
 * value set changed — so a new SDR added in GoHighLevel gets their sub-section
 * on the next sync instead of waiting for someone to press refresh.
 */
class SyncIntegrationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $integrationId) {}

    public function uniqueId(): string
    {
        return 'integration-sync:'.$this->integrationId;
    }

    public function uniqueFor(): int
    {
        return 900;
    }

    public function handle(SyncService $sync, LoopExpander $expander): void
    {
        $integration = Integration::find($this->integrationId);

        if (! $integration) {
            return; // Deleted between dispatch and run — nothing to do.
        }

        $sync->run($integration);

        $this->refreshLoops($expander);
    }

    /**
     * Re-expand any loop fed by this integration whose distinct values drifted.
     * Best-effort: a dashboard-side problem must never fail an otherwise good
     * sync, so failures are logged and swallowed per loop.
     */
    private function refreshLoops(LoopExpander $expander): void
    {
        $loops = LoopStatistic::with('dashboard')
            ->where('integration_id', $this->integrationId)
            ->get();

        foreach ($loops as $loop) {
            // Generated widgets need an owner (charts.user_id / metrics.user_id
            // are NOT NULL). No dashboard owner means no unattended refresh —
            // the manual refresh button still works, it has a request user.
            $userId = $loop->dashboard?->user_id;

            if (! $userId) {
                continue;
            }

            try {
                $expander->syncValues($loop, (int) $userId);
            } catch (\Throwable $e) {
                Log::warning('Loop refresh failed after sync', [
                    'loop' => $loop->id,
                    'integration' => $this->integrationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
