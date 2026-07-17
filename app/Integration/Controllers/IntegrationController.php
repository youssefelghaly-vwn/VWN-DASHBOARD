<?php

namespace App\Integration\Controllers;

use App\Http\Controllers\Controller;
use App\Integration\Jobs\SyncIntegrationJob;
use App\Integration\Models\Integration;
use App\Integration\Services\IntegrationManager;
use Illuminate\Http\Request;

/**
 * Manages integration connections generically. The same three actions
 * (connect / sync / disconnect) work for every provider — the provider itself
 * validates its own credentials in connect().
 */
class IntegrationController extends Controller
{
    public function __construct(private IntegrationManager $manager) {}

    public function index()
    {
        return view('admin.integrations', [
            'integrations' => Integration::with('latestSyncRun')->orderBy('name')->get(),
            'catalogue' => $this->manager->catalogue(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:'.implode(',', $this->manager->keys())],
        ]);

        $provider = $this->manager->get($data['provider']);
        $integration = new Integration(['provider' => $data['provider']]);

        try {
            $provider->connect($integration, $request->except(['_token', 'provider']));
        } catch (\Throwable $e) {
            return back()->withErrors(['integration' => $e->getMessage()])->withInput();
        }

        // First sync happens in the background so the page returns immediately.
        SyncIntegrationJob::dispatch($integration->id);

        return back()->with('status', "Connected {$integration->name}. First sync is running.");
    }

    public function sync(Integration $integration)
    {
        SyncIntegrationJob::dispatch($integration->id);

        return back()->with('status', "Sync queued for {$integration->name}.");
    }

    public function destroy(Integration $integration)
    {
        try {
            $integration->provider()->disconnect($integration);
        } catch (\Throwable) {
            // Even if the external teardown fails, remove the local connection.
        }

        $integration->delete();

        return back()->with('status', 'Integration disconnected.');
    }
}
