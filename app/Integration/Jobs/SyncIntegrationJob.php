<?php

namespace App\Integration\Jobs;

use App\Integration\Models\Integration;
use App\Integration\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The one job that syncs any integration. It doesn't know or care which provider
 * runs — it just resolves the integration and lets the SyncService drive.
 *
 * Syncs run OUT of the request cycle because a full pull (thousands of contacts,
 * calendar events, ad insights) far exceeds any HTTP timeout. Dashboards read
 * only the local rows the last run wrote. ShouldBeUnique stops overlapping syncs
 * of the same integration from stampeding an external API.
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

    public function handle(SyncService $sync): void
    {
        $integration = Integration::find($this->integrationId);

        if (! $integration) {
            return; // Deleted between dispatch and run — nothing to do.
        }

        $sync->run($integration);
    }
}
