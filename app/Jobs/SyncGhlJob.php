<?php

namespace App\Jobs;

use App\Models\GhlConnection;
use App\Services\GhlService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Populates the GHL data cache OUT of the request cycle.
 *
 * With ~9k contacts (and growing) a full sync is ~90 sequential API calls at
 * ~1.7s each — far past any web timeout. So the dashboard never fetches live:
 * it reads whatever the last run of this job wrote, and this job refreshes it
 * on a schedule (or on demand). ShouldBeUnique stops overlapping syncs from
 * stampeding the GHL API when several dashboards load at once.
 */
class SyncGhlJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // A full sweep of contacts + calendars can take a few minutes.
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $connectionId) {}

    /** One sync per connection at a time. */
    public function uniqueId(): string
    {
        return 'ghl-sync:'.$this->connectionId;
    }

    /** Keep the lock only as long as a sync could reasonably run. */
    public function uniqueFor(): int
    {
        return 900;
    }

    public function handle(GhlService $ghl): void
    {
        if (! GhlConnection::find($this->connectionId)) {
            return; // Disconnected between dispatch and run — nothing to do.
        }

        // fresh: true forces the fetchers to run and rewrite the durable cache.
        $ghl->all(fresh: true);
    }
}