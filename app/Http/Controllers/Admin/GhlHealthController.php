<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GhlConnection;
use App\Models\SheetSetting;
use App\Services\GhlService;
use Illuminate\Http\Request;

/**
 * "Data Health" — shows, per selected GoHighLevel object, what endpoint was
 * called, how many rows came back, and how long it took. This turns the opaque
 * "the dashboard is slow" into a per-object breakdown so an admin can see which
 * object is expensive and deselect it in Settings.
 *
 * Timings are read from the durable meta cache (no fetch). `?refresh=1` runs a
 * fresh, timed sync and then re-reads them.
 */
class GhlHealthController extends Controller
{
    public function __invoke(Request $request, GhlService $ghl)
    {
        $connection = GhlConnection::current();
        $isGhl      = SheetSetting::current()?->source === 'ghl';

        $error = null;

        if ($request->boolean('refresh') && $connection && $isGhl) {
            $ghl->ensureFresh();
        }


        $meta = ($connection && $isGhl) ? $ghl->lastMeta() : [];

        return view('admin.ghl-health', [
            'connection' => $connection,
            'isGhl'      => $isGhl,
            'meta'       => $meta,
            'available'  => $ghl->availableSheets(),
            'selected'   => $connection ? $ghl->selectedSheets() : [],
            'error'      => $error,
            'syncing'    => ($connection && $isGhl) ? $ghl->isSyncing() : false,

        ]);
    }
}
