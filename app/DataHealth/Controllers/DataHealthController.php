<?php

namespace App\DataHealth\Controllers;

use App\DataHealth\Services\DataHealthService;
use App\Http\Controllers\Controller;
use App\Integration\Jobs\SyncIntegrationJob;
use App\Integration\Models\Integration;
use Illuminate\Http\Request;

/**
 * Generic Data Health page. Works for every integration with zero
 * provider-specific logic — it just renders the DataHealthService report.
 */
class DataHealthController extends Controller
{
    public function index(DataHealthService $health)
    {
        return view('admin.data-health', [
            'report' => $health->report(),
        ]);
    }

    /** Kick a fresh sync for one integration, out of the request cycle. */
    public function sync(Request $request, Integration $integration)
    {
        SyncIntegrationJob::dispatch($integration->id);

        return back()->with('status', "Sync queued for {$integration->name}.");
    }
}
