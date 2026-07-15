<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Metric;
use App\Services\MetricService;
use Illuminate\Http\Request;

class MetricController extends Controller
{
    private const AGGS = ['count', 'count_if', 'percent_if', 'sum', 'avg', 'min', 'max'];
    private const OPS  = ['eq', 'neq', 'contains', 'not_contains', 'gt', 'lt', 'not_empty', 'empty'];

    public function index(Request $request, MetricService $metrics)
    {
        $payload = Metric::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('position')
            ->get()
            ->map(fn (Metric $m) => $metrics->build($m));

        return response()->json($payload);
    }

    public function store(Request $request, MetricService $metrics)
    {
        $metric = Metric::create($this->validated($request) + [
            'user_id'  => $request->user()->id,
            'position' => (int) Metric::where('user_id', $request->user()->id)->max('position') + 1,
        ]);

        return response()->json($metrics->build($metric), 201);
    }

    public function update(Request $request, Metric $metric, MetricService $metrics)
    {
        abort_unless($metric->user_id === $request->user()->id, 403);

        $metric->update($this->validated($request));

        return response()->json($metrics->build($metric->fresh()));
    }

    public function destroy(Request $request, Metric $metric)
    {
        abort_unless($metric->user_id === $request->user()->id, 403);

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
            'title'     => ['required', 'string', 'max:80'],
            'subtitle'  => ['nullable', 'string', 'max:80'],
            'mode'      => ['required', 'in:simple,formula'],
            'format'    => ['required', 'in:number,percent,currency'],
            'decimals'  => ['required', 'integer', 'min:0', 'max:4'],
            'accent'    => ['boolean'],

            'sheet'           => ['required_if:mode,simple', 'nullable', 'string'],
            'agg'             => ['required_if:mode,simple', 'nullable', 'in:'.implode(',', self::AGGS)],
            'column'          => ['nullable', 'string'],
            'filter_column'   => ['nullable', 'string'],
            'filter_operator' => ['nullable', 'in:'.implode(',', self::OPS)],
            'filter_value'    => ['nullable', 'string', 'max:120'],

            'expression' => ['required_if:mode,formula', 'nullable', 'string', 'max:200', 'regex:/^[\w\s{}+\-*\/().]+$/'],
            'variables'  => ['required_if:mode,formula', 'nullable', 'array'],
            'variables.*.sheet'           => ['required', 'string'],
            'variables.*.agg'             => ['required', 'in:'.implode(',', self::AGGS)],
            'variables.*.column'          => ['nullable', 'string'],
            'variables.*.filter_column'   => ['nullable', 'string'],
            'variables.*.filter_operator' => ['nullable', 'in:'.implode(',', self::OPS)],
            'variables.*.filter_value'    => ['nullable', 'string', 'max:120'],
        ];

        $data = $request->validate($rules);
        $data['accent'] = $request->boolean('accent');

        return $data;
    }
}