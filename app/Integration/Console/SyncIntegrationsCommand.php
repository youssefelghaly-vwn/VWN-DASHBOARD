<?php

namespace App\Integration\Console;

use App\Integration\Jobs\SyncIntegrationJob;
use App\Integration\Models\Integration;
use App\Integration\Services\SyncService;
use Illuminate\Console\Command;

/**
 * Syncs every connected integration. Scheduled to run every 5 minutes so local
 * data stays fresh without anyone clicking "Sync".
 *
 * By default it QUEUES one SyncIntegrationJob per integration (the same path as
 * the manual Sync button — fast, non-blocking, guarded against overlap). On a
 * host with no queue worker, pass --sync to run each sync inline instead.
 */
class SyncIntegrationsCommand extends Command
{
    protected $signature = 'integrations:sync {--sync : Run each sync now, in-process, instead of queueing}';

    protected $description = 'Sync every connected integration (queues one job each by default).';

    public function handle(SyncService $sync): int
    {
        $integrations = Integration::where('status', 'connected')->get();

        if ($integrations->isEmpty()) {
            $this->info('No connected integrations to sync.');

            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            if ($this->option('sync')) {
                try {
                    $sync->run($integration);
                    $this->info("Synced: {$integration->name}");
                } catch (\Throwable $e) {
                    $this->error("Failed: {$integration->name} — {$e->getMessage()}");
                }

                continue;
            }

            SyncIntegrationJob::dispatch($integration->id);
            $this->info("Queued: {$integration->name}");
        }

        return self::SUCCESS;
    }
}
