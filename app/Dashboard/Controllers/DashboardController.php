<?php

namespace App\Dashboard\Controllers;

use App\Dashboard\Models\Chart;
use App\Dashboard\Models\Dashboard;
use App\Dashboard\Services\DashboardData;
use App\Http\Controllers\Controller;
use App\Integration\Services\RecordReader;
use Illuminate\Http\Request;

/**
 * Renders and serves data for any dashboard. Dashboards read ONLY local synced
 * rows (via RecordReader / DashboardData) — never an external API — so they're
 * fast, cacheable, and can freely combine integrations.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $dashboard = Dashboard::where('is_default', true)->first()
            ?? Dashboard::orderBy('position')->first();

        if (! $dashboard) {
            return view('admin.dashboard', [
                'dashboard' => null,
                'schema' => [],
                'charts' => collect(),
            ]);
        }

        return redirect()->route('admin.dashboards.show', $dashboard->slug);
    }

    public function show(Dashboard $dashboard, RecordReader $reader)
    {
        return view('admin.dashboard', [
            'dashboard' => $dashboard,
            'schema' => $reader->schema(),
            'charts' => $dashboard->charts,
        ]);
    }

    public function chartData(Dashboard $dashboard, DashboardData $data)
    {
        $payload = $dashboard->charts->map(fn (Chart $c) => $data->buildChart($c));

        return response()->json($payload);
    }

    public function tableData(Request $request, RecordReader $reader)
    {
        $request->validate([
            'integration_id' => ['required', 'integer'],
            'dataset' => ['required', 'string'],
        ]);

        $integrationId = (int) $request->integer('integration_id');
        $dataset = (string) $request->string('dataset');

        return response()->json([
            'columns' => $reader->columns($integrationId, $dataset),
            'rows' => $reader->rows($integrationId, $dataset),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $dashboard = Dashboard::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'position' => (int) Dashboard::max('position') + 1,
        ]);

        return redirect()->route('admin.dashboards.show', $dashboard->slug)
            ->with('status', 'Dashboard created.');
    }

    public function destroy(Dashboard $dashboard)
    {
        $dashboard->delete();

        return redirect()->route('dashboard')->with('status', 'Dashboard deleted.');
    }
}
