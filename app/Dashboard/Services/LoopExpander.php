<?php

namespace App\Dashboard\Services;

use App\Dashboard\Models\Chart;
use App\Dashboard\Models\LoopStatistic;
use App\Dashboard\Models\Metric;
use App\Dashboard\Models\Section;
use App\Integration\Services\RecordReader;
use App\Support\FiltersRows;
use Illuminate\Support\Facades\DB;

/**
 * Turns a LoopStatistic definition into real widgets: for every distinct value
 * of the loop column it creates a sub-section (named after the value) and a
 * copy of each template metric/chart, with a "{column} = value" condition
 * injected so the copy only reflects that value.
 *
 * Re-runnable: expanding again wipes the loop's previously generated
 * sub-sections + widgets and rebuilds them, so new values (e.g. a new SDR)
 * appear on refresh and removed ones disappear.
 */
class LoopExpander
{
    use FiltersRows;

    /** Hard cap so a high-cardinality column (e.g. Contact) can't spawn thousands of widgets. */
    public const MAX_VALUES = 100;

    public function __construct(private RecordReader $reader) {}

    /**
     * @return array{values: array<int, string>, truncated: bool, metrics: int, charts: int}
     */
    public function expand(LoopStatistic $loop, int $userId): array
    {
        $all = $this->distinctValues($loop);
        $truncated = count($all) > self::MAX_VALUES;
        $values = array_slice($all, 0, self::MAX_VALUES);

        $templates = $loop->templates ?? [];
        $metricTemplates = $templates['metrics'] ?? [];
        $chartTemplates = $templates['charts'] ?? [];

        $metricCount = 0;
        $chartCount = 0;

        DB::transaction(function () use ($loop, $userId, $values, $metricTemplates, $chartTemplates, &$metricCount, &$chartCount) {
            // Wipe anything this loop generated last time.
            Chart::where('loop_id', $loop->id)->delete();
            Metric::where('loop_id', $loop->id)->delete();
            if ($loop->section_id) {
                Section::where('parent_id', $loop->section_id)->delete();
            }

            foreach ($values as $i => $value) {
                $sub = Section::create([
                    'dashboard_id' => $loop->dashboard_id,
                    'parent_id' => $loop->section_id,
                    'title' => $value,
                    'position' => $i,
                ]);

                foreach ($metricTemplates as $j => $tpl) {
                    Metric::create($this->metricAttributes($tpl, $loop, $value, $userId, $sub->id, $j));
                    $metricCount++;
                }

                foreach ($chartTemplates as $j => $tpl) {
                    Chart::create($this->chartAttributes($tpl, $loop, $value, $userId, $sub->id, $j));
                    $chartCount++;
                }
            }
        });

        return ['values' => $values, 'truncated' => $truncated, 'metrics' => $metricCount, 'charts' => $chartCount];
    }

    /** Delete every section + widget a loop produced (used before deleting the loop itself). */
    public function purge(LoopStatistic $loop): void
    {
        DB::transaction(function () use ($loop) {
            Chart::where('loop_id', $loop->id)->delete();
            Metric::where('loop_id', $loop->id)->delete();
            if ($loop->section_id) {
                Section::where('parent_id', $loop->section_id)->delete();
                Section::where('id', $loop->section_id)->delete();
            }
        });
    }

    /**
     * Distinct, non-empty values of the loop column, optionally narrowed by a
     * value-level condition (e.g. Owner "contains" SDR), sorted and de-duped.
     *
     * @return array<int, string>
     */
    public function distinctValues(LoopStatistic $loop): array
    {
        $rows = $this->reader->rows($loop->integration_id, $loop->dataset);

        $values = collect($rows)
            ->map(fn ($r) => trim((string) ($r[$loop->column] ?? '')))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values()
            ->all();

        if ($loop->value_operator) {
            // Reuse the shared filter semantics by treating each value as a row.
            $pseudo = array_map(fn ($v) => ['v' => $v], $values);
            $kept = $this->applyOneFilter($pseudo, 'v', $loop->value_operator, $loop->value_match ?? '');
            $values = array_map(fn ($r) => $r['v'], $kept);
        }

        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return $values;
    }

    /** Build a Metric row from a template, scoped to one loop value. */
    private function metricAttributes(array $tpl, LoopStatistic $loop, string $value, int $userId, int $sectionId, int $position): array
    {
        $cond = ['column' => $loop->column, 'operator' => 'eq', 'value' => $value];
        $mode = $tpl['mode'] ?? 'simple';

        if ($mode === 'formula') {
            // Scope every variable to this value so the whole formula is per-value.
            $vars = $tpl['variables'] ?? [];
            foreach ($vars as $k => $v) {
                $v['filters'] = array_merge($v['filters'] ?? [], [$cond]);
                $vars[$k] = $v;
            }
            $tpl['variables'] = $vars;
        } else {
            $tpl['filters'] = array_merge($tpl['filters'] ?? [], [$cond]);
        }

        return [
            'dashboard_id' => $loop->dashboard_id,
            'user_id' => $userId,
            'section_id' => $sectionId,
            'loop_id' => $loop->id,
            'position' => $position,
            'title' => $tpl['title'] ?? $loop->column,
            'subtitle' => $tpl['subtitle'] ?? null,
            'mode' => $mode,
            'integration_id' => $tpl['integration_id'] ?? $loop->integration_id,
            'sheet' => $tpl['sheet'] ?? $loop->dataset,
            'agg' => $tpl['agg'] ?? 'count',
            'column' => $tpl['column'] ?? null,
            'filters' => $tpl['filters'] ?? [],
            'expression' => $tpl['expression'] ?? null,
            'variables' => $tpl['variables'] ?? null,
            'format' => $tpl['format'] ?? 'number',
            'decimals' => $tpl['decimals'] ?? 0,
            'accent' => (bool) ($tpl['accent'] ?? false),
        ];
    }

    /** Build a Chart row from a template, scoped to one loop value. */
    private function chartAttributes(array $tpl, LoopStatistic $loop, string $value, int $userId, int $sectionId, int $position): array
    {
        $cond = ['column' => $loop->column, 'operator' => 'eq', 'value' => $value];

        return [
            'dashboard_id' => $loop->dashboard_id,
            'user_id' => $userId,
            'section_id' => $sectionId,
            'loop_id' => $loop->id,
            'position' => $position,
            'title' => $tpl['title'] ?? $loop->column,
            'type' => $tpl['type'] ?? 'bar',
            'integration_id' => $tpl['integration_id'] ?? $loop->integration_id,
            'sheet' => $tpl['sheet'] ?? $loop->dataset,
            'label_column' => $tpl['label_column'] ?? $loop->column,
            'aggregate' => $tpl['aggregate'] ?? 'count',
            'limit' => $tpl['limit'] ?? 10,
            'width' => $tpl['width'] ?? 'full',
            'height' => $tpl['height'] ?? null,
            'series' => $tpl['series'] ?? [['sheet' => $tpl['sheet'] ?? $loop->dataset, 'agg' => 'count', 'label' => 'Count']],
            'filters' => array_merge($tpl['filters'] ?? [], [$cond]),
        ];
    }
}
