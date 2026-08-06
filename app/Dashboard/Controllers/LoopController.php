<?php

namespace App\Dashboard\Controllers;

use App\Dashboard\Models\Dashboard;
use App\Dashboard\Models\LoopStatistic;
use App\Dashboard\Models\Section;
use App\Dashboard\Services\LoopExpander;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Loop statistics — define once, materialise per distinct value of a column.
 * A loop owns a parent section (its name); expanding fills it with one
 * sub-section per value, each holding copies of the template widgets scoped to
 * that value.
 */
class LoopController extends Controller
{
    private const OPS = ['eq', 'neq', 'contains', 'not_contains', 'has_all', 'has_any', 'not_has_any'];

    public function __construct(private LoopExpander $expander) {}

    public function index(Dashboard $dashboard)
    {
        return response()->json($this->payload($dashboard));
    }

    public function store(Request $request, Dashboard $dashboard)
    {
        $data = $this->validated($request);

        $section = Section::create([
            'dashboard_id' => $dashboard->id,
            'title' => $data['name'],
            'position' => (int) $dashboard->sections()->max('position') + 1,
        ]);

        $loop = LoopStatistic::create([
            'dashboard_id' => $dashboard->id,
            'section_id' => $section->id,
            'name' => $data['name'],
            'integration_id' => $data['integration_id'] ?? null,
            'dataset' => $data['dataset'],
            'column' => $data['column'],
            'value_operator' => $data['value_operator'] ?? null,
            'value_match' => $data['value_match'] ?? null,
            // Pull the FULL template configs from raw input: $request->validate()
            // only returns whitelisted keys, which would strip filters/agg/series
            // etc. off each template. Safe to take raw here — LoopExpander only
            // mass-assigns fillable columns and the render pipeline tolerates a
            // bad config (it shows an error tile, never 500s).
            'templates' => [
                'metrics' => array_values($request->input('metrics', [])),
                'charts' => array_values($request->input('charts', [])),
            ],
            'position' => (int) $dashboard->loops()->max('position') + 1,
        ]);

        $result = $this->expander->expand($loop, (int) $request->user()->id);

        return response()->json([
            'loops' => $this->payload($dashboard),
            'result' => $result,
        ], 201);
    }

    public function update(Request $request, LoopStatistic $loop)
    {
        $data = $this->validated($request);

        // Keep the same parent section, just rename it to match.
        if ($loop->section) {
            $loop->section->update(['title' => $data['name']]);
        }

        $loop->update([
            'name' => $data['name'],
            'integration_id' => $data['integration_id'] ?? null,
            'dataset' => $data['dataset'],
            'column' => $data['column'],
            'value_operator' => $data['value_operator'] ?? null,
            'value_match' => $data['value_match'] ?? null,
            'templates' => [
                'metrics' => array_values($request->input('metrics', [])),
                'charts' => array_values($request->input('charts', [])),
            ],
        ]);

        $result = $this->expander->expand($loop, (int) $request->user()->id);

        return response()->json([
            'loops' => $this->payload($loop->dashboard),
            'result' => $result,
        ]);
    }

    public function refresh(Request $request, LoopStatistic $loop)
    {
        $result = $this->expander->expand($loop, (int) $request->user()->id);

        return response()->json([
            'loops' => $this->payload($loop->dashboard),
            'result' => $result,
        ]);
    }

    public function destroy(LoopStatistic $loop)
    {
        $dashboard = $loop->dashboard;
        $this->expander->purge($loop);
        $loop->delete();

        return response()->json($this->payload($dashboard));
    }

    /** @return array<int, array<string, mixed>> */
    private function payload(Dashboard $dashboard): array
    {
        return $dashboard->loops()->orderBy('position')->get()
            ->map(fn (LoopStatistic $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'section_id' => $l->section_id,
                'integration_id' => $l->integration_id,
                'dataset' => $l->dataset,
                'column' => $l->column,
                'value_operator' => $l->value_operator,
                'value_match' => $l->value_match,
                'templates' => $l->templates ?? ['metrics' => [], 'charts' => []],
                'values' => count($this->expander->distinctValues($l)),
            ])->all();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'integration_id' => ['nullable', 'integer', 'exists:integrations,id'],
            'dataset' => ['required', 'string'],
            'column' => ['required', 'string'],
            'value_operator' => ['nullable', 'in:'.implode(',', self::OPS)],
            'value_match' => ['nullable', 'string', 'max:255'],
            // Template widgets — free-form configs (same shape the metric/chart
            // builders POST). Validated structurally; the render pipeline
            // tolerates a bad config by showing an error tile rather than 500ing.
            'metrics' => ['array'],
            'metrics.*' => ['array'],
            'metrics.*.title' => ['required', 'string', 'max:80'],
            'charts' => ['array'],
            'charts.*' => ['array'],
            'charts.*.title' => ['required', 'string', 'max:120'],
        ]);
    }
}
