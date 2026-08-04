<?php

namespace App\Dashboard\Controllers;

use App\Dashboard\Models\Dashboard;
use App\Dashboard\Models\Metric;
use App\Http\Controllers\Controller;
use App\Metric\Services\MetricService;
use Illuminate\Http\Request;

class MetricController extends Controller
{
    private const AGGS = ['count', 'count_if', 'percent_if', 'sum', 'avg', 'min', 'max'];

    private const OPS = ['eq', 'neq', 'contains', 'not_contains', 'gt', 'lt', 'has_all', 'has_any', 'not_has_any', 'not_empty', 'empty'];

    public function index(Dashboard $dashboard, MetricService $metrics)
    {
        $payload = $dashboard->metrics->map(fn (Metric $m) => $metrics->build($m));

        return response()->json($payload);
    }

    public function store(Request $request, Dashboard $dashboard, MetricService $metrics)
    {
        $metric = Metric::create($this->validated($request) + [
            'user_id' => $request->user()->id,
            'dashboard_id' => $dashboard->id,
            'position' => (int) Metric::where('dashboard_id', $dashboard->id)->max('position') + 1,
        ]);

        return response()->json($metrics->build($metric), 201);
    }

    public function update(Request $request, Metric $metric, MetricService $metrics)
    {
        $metric->update($this->validated($request));

        return response()->json($metrics->build($metric->fresh()));
    }

    public function destroy(Metric $metric)
    {
        $metric->delete();

        return response()->noContent();
    }

    /** Live preview so the user sees the number before saving. */
    public function preview(Request $request, MetricService $metrics)
    {
        $metric = new Metric($this->validated($request));

        return response()->json($metrics->build($metric));
    }

    private function validated(Request $request): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:80'],
            'subtitle' => ['nullable', 'string', 'max:80'],
            'mode' => ['required', 'in:simple,formula'],
            'format' => ['required', 'in:number,percent,currency'],
            'decimals' => ['required', 'integer', 'min:0', 'max:4'],
            'accent' => ['boolean'],
            'integration_id' => ['nullable', 'integer', 'exists:integrations,id'],

            'sheet' => ['required_if:mode,simple', 'nullable', 'string'],
            'agg' => ['required_if:mode,simple', 'nullable', 'in:'.implode(',', self::AGGS)],
            'column' => ['nullable', 'string'],
            'filter_column' => ['nullable', 'string'],
            'filter_operator' => ['nullable', 'in:'.implode(',', self::OPS)],
            'filter_value' => ['nullable', 'string', 'max:255'],
            'filters' => ['nullable', 'array'],
            'filters.*.column' => ['nullable', 'string'],
            'filters.*.operator' => ['nullable', 'in:'.implode(',', self::OPS)],
            'filters.*.value' => ['nullable', 'string', 'max:255'],

            'expression' => ['required_if:mode,formula', 'nullable', 'string', 'max:200', 'regex:/^[\w\s{}+\-*\/().]+$/'],
            'variables' => ['required_if:mode,formula', 'nullable', 'array'],
            'variables.*.integration_id' => ['nullable', 'integer', 'exists:integrations,id'],
            'variables.*.sheet' => ['required', 'string'],
            'variables.*.agg' => ['required', 'in:'.implode(',', self::AGGS)],
            'variables.*.column' => ['nullable', 'string'],
            'variables.*.filter_column' => ['nullable', 'string'],
            'variables.*.filter_operator' => ['nullable', 'in:'.implode(',', self::OPS)],
            'variables.*.filter_value' => ['nullable', 'string', 'max:255'],
            'variables.*.filters' => ['nullable', 'array'],
            'variables.*.filters.*.column' => ['nullable', 'string'],
            'variables.*.filters.*.operator' => ['nullable', 'in:'.implode(',', self::OPS)],
            'variables.*.filters.*.value' => ['nullable', 'string', 'max:255'],
        ];

        $data = $request->validate($rules);
        $data['accent'] = $request->boolean('accent');

        return $data;
    }
}
