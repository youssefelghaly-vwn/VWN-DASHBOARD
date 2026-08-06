<?php

namespace App\Dashboard\Controllers;

use App\Dashboard\Models\Chart;
use App\Dashboard\Models\Dashboard;
use App\Dashboard\Models\Metric;
use App\Dashboard\Models\Section;
use App\Dashboard\Services\DashboardData;
use App\Http\Controllers\Controller;
use App\Integration\Jobs\SyncIntegrationJob;
use App\Integration\Models\Integration;
use App\Integration\Services\RecordReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
            'syncStatus' => $this->syncSnapshot(),
        ]);
    }

    /**
     * A small "how fresh is the data" snapshot for the dashboard header —
     * the most recent sync across all integrations, plus connected counts.
     */
    private function syncSnapshot(): array
    {
        $max = Integration::max('last_synced_at');
        $at = $max ? Carbon::parse($max) : null;

        return [
            'at' => $at?->toIso8601String(),
            'human' => $at?->diffForHumans(),
            'connected' => (int) Integration::where('status', 'connected')->count(),
            'total' => (int) Integration::count(),
        ];
    }

    /** JSON version of the snapshot — polled by the dashboard to live-update. */
    public function syncStatus()
    {
        return response()->json($this->syncSnapshot());
    }

    /** Queue a sync for every connected integration (the header "Sync now" button). */
    public function syncAll()
    {
        Integration::where('status', 'connected')->each(
            fn (Integration $i) => SyncIntegrationJob::dispatch($i->id)
        );

        return response()->json($this->syncSnapshot());
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

    /**
     * Persist the whole layout in one shot: section order/nesting and each
     * widget's section + position. Only ids that actually belong to this
     * dashboard are touched, so a stale or forged id is silently ignored.
     */
    public function reorderLayout(Request $request, Dashboard $dashboard)
    {
        $data = $request->validate([
            'sections' => ['array'],
            'sections.*.id' => ['required', 'integer'],
            'sections.*.parent_id' => ['nullable', 'integer'],
            'sections.*.position' => ['required', 'integer'],
            'charts' => ['array'],
            'charts.*.id' => ['required', 'integer'],
            'charts.*.section_id' => ['nullable', 'integer'],
            'charts.*.position' => ['required', 'integer'],
            'metrics' => ['array'],
            'metrics.*.id' => ['required', 'integer'],
            'metrics.*.section_id' => ['nullable', 'integer'],
            'metrics.*.position' => ['required', 'integer'],
        ]);

        // Ids we're allowed to touch — everything owned by this dashboard.
        $sectionIds = $dashboard->sections()->pluck('id')->flip();
        $chartIds = $dashboard->charts()->pluck('id')->flip();
        $metricIds = $dashboard->metrics()->pluck('id')->flip();

        // A widget may only be placed into a section of THIS dashboard (or null).
        $validSection = fn ($id) => $id === null || isset($sectionIds[$id]);

        DB::transaction(function () use ($data, $sectionIds, $chartIds, $metricIds, $validSection) {
            foreach ($data['sections'] ?? [] as $s) {
                if (! isset($sectionIds[$s['id']])) {
                    continue;
                }
                $parent = $s['parent_id'] ?? null;
                // Parent must be another section of this dashboard, not itself.
                if ($parent !== null && ($parent === $s['id'] || ! isset($sectionIds[$parent]))) {
                    $parent = null;
                }
                Section::where('id', $s['id'])->update([
                    'parent_id' => $parent,
                    'position' => $s['position'],
                ]);
            }

            foreach ($data['charts'] ?? [] as $c) {
                if (! isset($chartIds[$c['id']]) || ! $validSection($c['section_id'] ?? null)) {
                    continue;
                }
                Chart::where('id', $c['id'])->update([
                    'section_id' => $c['section_id'] ?? null,
                    'position' => $c['position'],
                ]);
            }

            foreach ($data['metrics'] ?? [] as $m) {
                if (! isset($metricIds[$m['id']]) || ! $validSection($m['section_id'] ?? null)) {
                    continue;
                }
                Metric::where('id', $m['id'])->update([
                    'section_id' => $m['section_id'] ?? null,
                    'position' => $m['position'],
                ]);
            }
        });

        return response()->noContent();
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
