<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GhlConnection;
use App\Models\SheetSetting;
use App\Services\Ghl\GhlClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GhlConnectionController extends Controller
{
    public function __construct(private GhlClient $client) {}

    /**
     * Save the private integration token + location id, verify it with a live
     * call, and (on success) make GHL the active source.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'access_token' => ['required', 'string', 'min:20'],
            'location_id'  => ['required', 'string'],
        ]);

        // Build a throwaway connection to test the credentials before persisting.
        $probe = new GhlConnection([
            'location_id'  => trim($data['location_id']),
            'access_token' => trim($data['access_token']),
        ]);

        try {
            // A cheap call that also gives us the location's display name.
            $location = $this->client->get($probe, '/locations/'.$probe->location_id);
            $name     = $location['location']['name'] ?? $location['name'] ?? null;
        } catch (\Throwable $e) {
            return back()->withErrors(['access_token' => $e->getMessage()])->withInput();
        }

        Cache::flush();

        GhlConnection::updateOrCreate(
            ['location_id' => $probe->location_id],
            [
                'access_token'  => $probe->access_token,
                'location_name' => $name,
            ]
        );

        // Flip the dashboard to GHL; Sheets settings stay intact underneath.
        $setting = SheetSetting::current() ?? new SheetSetting();
        $setting->source = 'ghl';
        $setting->save();

        return back()->with('status', 'Connected to GoHighLevel'.($name ? " — {$name}" : '').'.');
    }

    public function destroy()
    {
        GhlConnection::query()->delete();
        Cache::flush();

        if ($setting = SheetSetting::current()) {
            $setting->update(['source' => 'sheets']);
        }

        return back()->with('status', 'Disconnected from GoHighLevel. Reverted to Google Sheets.');
    }
}