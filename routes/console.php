<?php

use App\Integration\Jobs\SyncIntegrationJob;
use App\Integration\Models\Integration;
use Illuminate\Support\Facades\Schedule;

// Keep every connected integration's local data fresh, out of the request cycle.
Schedule::call(function () {
    Integration::where('status', 'connected')->each(
        fn (Integration $integration) => SyncIntegrationJob::dispatch($integration->id)
    );
})->everyFifteenMinutes();
