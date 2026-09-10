<?php

namespace App\Integration\Providers;

use App\Integration\Models\Integration;

/**
 * Thin public wrapper around CloudTalkProvider's fetchCalls()/callRows(),
 * for the same reason DebugDateFilter wraps FiltersRows: those two methods
 * are private on purpose (nothing outside the provider should call the API
 * or reshape rows directly), but a debug route needs to run the exact real
 * transformation live, not a hand-copied stand-in that could drift from it.
 */
class DebugCloudTalkProvider extends CloudTalkProvider
{
    /** Live call to CloudTalk's API — same request fetchCalls() makes during a real sync. */
    public function debugFetchCalls(Integration $integration): array
    {
        return $this->fetchCalls($integration);
    }

    /** Runs raw call objects through the exact same shaping callRows() does before write(). */
    public function debugCallRows(array $calls, array $agents = []): array
    {
        return $this->callRows($calls, $agents);
    }
}