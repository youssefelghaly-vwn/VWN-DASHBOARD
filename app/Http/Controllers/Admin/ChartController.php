<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chart;
use App\Services\SourceManager;
use Illuminate\Http\Request;

class ChartController extends Controller
{
    private const TYPES = [
        'bar', 'horizontalBar', 'stackedBar', 'line', 'area',
        'pie', 'doughnut', 'polarArea', 'radar', 'scatter', 'bubble',
    ];
    private const AGGS = ['count', 'sum', 'avg', 'min', 'max'];

    public function store(Request $request, SourceManager $sources)
    {
        $chart = Chart::create($this->validated($request) + [
            'user_id'  => $request->user()->id,
            'position' => (int) Chart::where('user_id', $request->user()->id)->max('position') + 1,
        ]);

        return response()->json($sources->active()->buildChart($chart), 201);
    }

    public function update(Request $request, Chart $chart, SourceManager $sources)
    {
        abort_unless($chart->user_id === $request->user()->id, 403);

        $chart->update($this->validated($request));

        return response()->json($sources->active()->buildChart($chart->fresh()));
    }

    public function destroy(Request $request, Chart $chart)
    {
        abort_unless($chart->user_id === $request->user()->id, 403);

        $chart->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'          => ['required', 'string', 'max:120'],
            'type'           => ['required', 'in:'.implode(',', self::TYPES)],
            'sheet'          => ['required', 'string'],
            'label_column'   => ['required', 'string'],
            'aggregate'      => ['required', 'in:'.implode(',', self::AGGS)],
            'limit'          => ['required', 'integer', 'min:1', 'max:50'],
            'series'         => ['required', 'array', 'min:1'],
            'series.*.sheet'  => ['required', 'string'],
            'series.*.column' => ['nullable', 'string'],
            'series.*.agg'    => ['required', 'in:'.implode(',', self::AGGS)],
            'series.*.label'  => ['required', 'string', 'max:60'],
            'series.*.color'  => ['nullable', 'string', 'max:9'],
        ]);
    }
}