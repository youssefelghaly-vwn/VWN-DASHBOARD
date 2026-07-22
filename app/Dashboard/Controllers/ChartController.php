<?php

namespace App\Dashboard\Controllers;

use App\Dashboard\Models\Chart;
use App\Dashboard\Models\Dashboard;
use App\Dashboard\Services\DashboardData;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ChartController extends Controller
{
    private const TYPES = [
        'bar', 'horizontalBar', 'stackedBar', 'line', 'area',
        'pie', 'doughnut', 'polarArea', 'radar', 'scatter', 'bubble',
    ];

    private const AGGS = ['count', 'sum', 'avg', 'min', 'max'];

    private const OPS = ['eq', 'neq', 'contains', 'not_contains', 'gt', 'lt', 'not_empty', 'empty'];

    public function store(Request $request, Dashboard $dashboard, DashboardData $data)
    {
        $chart = Chart::create($this->validated($request) + [
            'user_id' => $request->user()->id,
            'dashboard_id' => $dashboard->id,
            'position' => (int) Chart::where('dashboard_id', $dashboard->id)->max('position') + 1,
        ]);

        return response()->json($data->buildChart($chart), 201);
    }

    public function update(Request $request, Chart $chart, DashboardData $data)
    {
        $chart->update($this->validated($request));

        return response()->json($data->buildChart($chart->fresh()));
    }

    public function destroy(Chart $chart)
    {
        $chart->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:'.implode(',', self::TYPES)],
            'integration_id' => ['nullable', 'integer', 'exists:integrations,id'],
            'sheet' => ['required', 'string'],
            'label_column' => ['required', 'string'],
            'aggregate' => ['required', 'in:'.implode(',', self::AGGS)],
            'limit' => ['required', 'integer', 'min:1', 'max:50'],
            'filters' => ['nullable', 'array'],
            'filters.*.column' => ['nullable', 'string'],
            'filters.*.operator' => ['nullable', 'in:'.implode(',', self::OPS)],
            'filters.*.value' => ['nullable', 'string', 'max:120'],
            'series' => ['required', 'array', 'min:1'],
            'series.*.integration_id' => ['nullable', 'integer', 'exists:integrations,id'],
            'series.*.sheet' => ['required', 'string'],
            'series.*.column' => ['nullable', 'string'],
            'series.*.agg' => ['required', 'in:'.implode(',', self::AGGS)],
            'series.*.label' => ['required', 'string', 'max:60'],
            'series.*.color' => ['nullable', 'string', 'max:9'],
        ]);
    }
}
