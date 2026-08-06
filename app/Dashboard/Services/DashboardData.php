<?php

namespace App\Dashboard\Services;

use App\Dashboard\Models\Chart;
use App\Integration\Services\RecordReader;
use App\Support\FiltersRows;

/**
 * Turns saved Chart widgets into Chart.js-ready payloads, reading ONLY local
 * synced rows via the RecordReader. Because each series resolves its own
 * integration + dataset, one chart can combine data from several integrations
 * (e.g. GHL contacts vs Meta spend).
 */
class DashboardData
{
    use FiltersRows;

    public const PALETTE = [
        '#4FE3A6', '#EE9F4E', '#E2694F', '#7FA396',
        '#2E9E76', '#C97A2A', '#8FBFAE', '#D98A6F',
    ];

    private const TYPE_MAP = [
        'bar' => ['type' => 'bar',       'axis' => 'x'],
        'horizontalBar' => ['type' => 'bar',       'axis' => 'y'],
        'stackedBar' => ['type' => 'bar',       'axis' => 'x', 'stacked' => true],
        'line' => ['type' => 'line',      'axis' => 'x'],
        'area' => ['type' => 'line',      'axis' => 'x', 'fill' => true],
        'pie' => ['type' => 'pie',       'axis' => 'x', 'perSlice' => true],
        'doughnut' => ['type' => 'doughnut',  'axis' => 'x', 'perSlice' => true],
        'polarArea' => ['type' => 'polarArea', 'axis' => 'x', 'perSlice' => true],
        'radar' => ['type' => 'radar',     'axis' => 'x', 'radarFill' => true],
        'scatter' => ['type' => 'scatter',   'axis' => 'x', 'xy' => true],
        'bubble' => ['type' => 'bubble',    'axis' => 'x', 'xy' => true],
    ];

    public function __construct(private RecordReader $reader) {}

    public function buildChart(Chart $chart): array
    {
        $map = self::TYPE_MAP[$chart->type] ?? self::TYPE_MAP['bar'];

        $labels = [];
        $datasets = [];

        foreach ($chart->series as $i => $s) {
            $integrationId = $s['integration_id'] ?? $chart->integration_id;
            $dataset = $s['dataset'] ?? $s['sheet'] ?? $chart->sheet;

            $rows = $this->reader->rows($integrationId, $dataset);
            $rows = $this->filterRows($rows, array_merge($chart->filters ?? [], $s['filters'] ?? []));

            $result = $this->aggregate(
                $rows,
                $chart->label_column,
                $s['column'] ?? null,
                $s['agg'] ?? $chart->aggregate,
                $chart->limit
            );

            if (! $labels) {
                $labels = $result['labels'];
                $values = $result['values'];
            } else {
                $lookup = array_combine($result['labels'], $result['values']);
                $values = array_map(fn ($l) => $lookup[$l] ?? 0, $labels);
            }

            $color = $s['color'] ?? self::PALETTE[$i % count(self::PALETTE)];

            $datasets[] = $this->decorateDataset([
                'label' => $s['label'] ?? ($s['column'] ?? 'Count'),
                'data' => $values,
                'backgroundColor' => $color,
                'borderColor' => $color,
                'borderWidth' => 1,
            ], $map, $color);
        }

        if (! empty($map['perSlice']) && $datasets) {
            $datasets[0]['backgroundColor'] = array_map(
                fn ($i) => self::PALETTE[$i % count(self::PALETTE)],
                array_keys($labels)
            );
            $datasets[0]['borderColor'] = '#FFFFFF';
            $datasets[0]['borderWidth'] = 2;
        }

        if (! empty($map['xy'])) {
            $bubble = $chart->type === 'bubble';
            foreach ($datasets as &$ds) {
                $ds['data'] = array_map(
                    fn ($v, $j) => $bubble
                        ? ['x' => $j, 'y' => $v, 'r' => max(5, min(24, (float) $v))]
                        : ['x' => $j, 'y' => $v],
                    $ds['data'],
                    array_keys($ds['data'])
                );
            }
            unset($ds);
        }

        return [
            'id' => $chart->id,
            'title' => $chart->title,
            'type' => $map['type'],
            'indexAxis' => $map['axis'],
            'stacked' => ! empty($map['stacked']),
            'section_id' => $chart->section_id,
            'width' => $chart->width ?: 'full',
            'height' => $chart->height,
            'position' => $chart->position,
            'labels' => $labels,
            'datasets' => $datasets,
            'config' => [
                'section_id' => $chart->section_id,
                'width' => $chart->width ?: 'full',
                'height' => $chart->height,
                'integration_id' => $chart->integration_id,
                'sheet' => $chart->sheet,
                'label_column' => $chart->label_column,
                'aggregate' => $chart->aggregate,
                'limit' => $chart->limit,
                'series' => $chart->series,
                'filters' => $chart->filters ?? [],
            ],
        ];
    }

    public function aggregate(
        array $rows,
        string $labelColumn,
        ?string $valueColumn = null,
        string $agg = 'count',
        int $limit = 10
    ): array {
        $buckets = [];

        foreach ($rows as $row) {
            $label = trim((string) ($row[$labelColumn] ?? ''));

            if ($label === '') {
                $label = 'Unspecified';
            }

            $buckets[$label][] = $valueColumn
                ? $this->numeric($row[$valueColumn] ?? null)
                : 1;
        }

        $reduced = [];

        foreach ($buckets as $label => $vals) {
            $vals = array_values(array_filter($vals, fn ($v) => $v !== null));

            $reduced[$label] = match ($agg) {
                'sum' => array_sum($vals),
                'avg' => $vals ? round(array_sum($vals) / count($vals), 2) : 0,
                'min' => $vals ? min($vals) : 0,
                'max' => $vals ? max($vals) : 0,
                default => count($vals),
            };
        }

        arsort($reduced);
        $reduced = array_slice($reduced, 0, $limit, true);

        return [
            'labels' => array_keys($reduced),
            'values' => array_values($reduced),
        ];
    }

    private function decorateDataset(array $ds, array $map, string $color): array
    {
        if (! empty($map['fill'])) {
            $ds['fill'] = true;
            $ds['tension'] = 0.35;
            $ds['backgroundColor'] = $color.'40';
        }

        if (! empty($map['radarFill'])) {
            $ds['fill'] = true;
            $ds['backgroundColor'] = $color.'33';
            $ds['borderWidth'] = 2;
        }

        return $ds;
    }

    private function numeric(mixed $v): ?float
    {
        if (is_numeric($v)) {
            return (float) $v;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $v);

        return $clean === '' || $clean === '-' ? null : (float) $clean;
    }
}
