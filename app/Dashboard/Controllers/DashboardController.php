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

    /**
     * Distinct, non-empty values for one column of a synced dataset — used by
     * the builder's cascading pickers (e.g. Pipeline, then Stage scoped to the
     * chosen Pipeline). `filters` is a JSON-encoded [{column, value}] list of
     * equality conditions narrowing which rows are considered.
     */
    public function distinct(Request $request, RecordReader $reader)
    {
        $data = $request->validate([
            'integration_id' => ['required', 'integer'],
            'dataset' => ['required', 'string'],
            'column' => ['required', 'string'],
            'filters' => ['nullable', 'string'],
        ]);

        $rows = $reader->rows((int) $data['integration_id'], $data['dataset']);

        $scopes = [];
        if (! empty($data['filters'])) {
            $decoded = json_decode($data['filters'], true);
            $scopes = is_array($decoded) ? $decoded : [];
        }

        foreach ($scopes as $scope) {
            $column = $scope['column'] ?? null;
            if (! $column) {
                continue;
            }

            $needle = trim((string) ($scope['value'] ?? ''));
            $rows = array_values(array_filter(
                $rows,
                fn ($row) => trim((string) ($row[$column] ?? '')) === $needle
            ));
        }

        $values = collect($rows)
            ->map(fn ($row) => trim((string) ($row[$data['column']] ?? '')))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->sort()
            ->values();

        return response()->json($values);
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
