<?php

namespace App\Metric\Services;

use App\Dashboard\Models\Metric;
use App\Integration\Services\RecordReader;
use RuntimeException;

/**
 * Metric engine — computes a single number from local synced rows. Fully
 * source-agnostic: it reads rows through the RecordReader by integration +
 * dataset, so the same formula works over GoHighLevel, Google Sheets, or Meta
 * data with no provider-specific branches.
 *
 * The formula evaluator (safe shunting-yard) is unchanged from before.
 */
class MetricService
{
    public function __construct(private RecordReader $reader) {}

    public function build(Metric $metric): array
    {
        try {
            $value = $metric->mode === 'formula'
                ? $this->evaluateFormula($metric)
                : $this->computeSimple($this->simpleConfig($metric));

            $error = null;
        } catch (\Throwable $e) {
            $value = null;
            $error = $e->getMessage();
        }

        return [
            'id' => $metric->id,
            'title' => $metric->title,
            'subtitle' => $metric->subtitle,
            'accent' => $metric->accent,
            'value' => $value,
            'display' => $value === null ? '—' : $this->format($value, $metric->format, $metric->decimals),
            'error' => $error,
            'config' => [
                'mode' => $metric->mode,
                'integration_id' => $metric->integration_id,
                'sheet' => $metric->sheet,
                'agg' => $metric->agg,
                'column' => $metric->column,
                'filter_column' => $metric->filter_column,
                'filter_operator' => $metric->filter_operator,
                'filter_value' => $metric->filter_value,
                'expression' => $metric->expression,
                'variables' => $metric->variables ?? [],
                'format' => $metric->format,
                'decimals' => $metric->decimals,
                'title' => $metric->title,
                'subtitle' => $metric->subtitle,
                'accent' => $metric->accent,
            ],
        ];
    }

    private function simpleConfig(Metric $m): array
    {
        return [
            'integration_id' => $m->integration_id,
            'sheet' => $m->sheet,
            'agg' => $m->agg,
            'column' => $m->column,
            'filter_column' => $m->filter_column,
            'filter_operator' => $m->filter_operator,
            'filter_value' => $m->filter_value,
        ];
    }

    public function computeSimple(array $cfg): float
    {
        $rows = $this->reader->rows(
            $cfg['integration_id'] ?? null,
            $cfg['sheet'] ?? ''
        );

        $total = count($rows);
        $matched = $this->applyFilter($rows, $cfg);
        $agg = $cfg['agg'] ?? 'count';

        if ($agg === 'count' || $agg === 'count_if') {
            return (float) count($matched);
        }

        if ($agg === 'percent_if') {
            return $total ? count($matched) / $total * 100 : 0.0;
        }

        $column = $cfg['column'] ?? null;

        if (! $column) {
            throw new RuntimeException("Aggregate “{$agg}” needs a value column.");
        }

        $values = [];

        foreach ($matched as $row) {
            $n = $this->numeric($row[$column] ?? null);
            if ($n !== null) {
                $values[] = $n;
            }
        }

        if (! $values) {
            return 0.0;
        }

        return (float) match ($agg) {
            'sum' => array_sum($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
            default => throw new RuntimeException("Unknown aggregate: {$agg}"),
        };
    }

    private function applyFilter(array $rows, array $cfg): array
    {
        $col = $cfg['filter_column'] ?? null;
        $op = $cfg['filter_operator'] ?? null;

        if (! $col || ! $op) {
            return $rows;
        }

        $needle = mb_strtolower(trim((string) ($cfg['filter_value'] ?? '')));

        return array_values(array_filter($rows, function ($row) use ($col, $op, $needle) {
            $raw = $row[$col] ?? '';
            $hay = mb_strtolower(trim((string) $raw));
            $num = $this->numeric($raw);
            $need = $this->numeric($needle);

            return match ($op) {
                'eq' => $hay === $needle,
                'neq' => $hay !== $needle,
                'contains' => $needle !== '' && str_contains($hay, $needle),
                'not_contains' => $needle === '' || ! str_contains($hay, $needle),
                'gt' => $num !== null && $need !== null && $num > $need,
                'lt' => $num !== null && $need !== null && $num < $need,
                'not_empty' => $hay !== '',
                'empty' => $hay === '',
                default => true,
            };
        }));
    }

    private function evaluateFormula(Metric $metric): float
    {
        $expression = (string) $metric->expression;
        $variables = $metric->variables ?? [];

        if (trim($expression) === '') {
            throw new RuntimeException('Formula is empty.');
        }

        $resolved = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($variables, $metric) {
            $name = $m[1];

            if (! isset($variables[$name])) {
                throw new RuntimeException("Formula references undefined variable “{$name}”.");
            }

            // A variable may target its own integration; fall back to the metric's.
            $cfg = $variables[$name];
            $cfg['integration_id'] = $cfg['integration_id'] ?? $metric->integration_id;

            return (string) $this->computeSimple($cfg);
        }, $expression);

        return $this->evaluateArithmetic($resolved);
    }

    public function evaluateArithmetic(string $expr): float
    {
        if (! preg_match('/^[0-9+\-*\/(). \t]+$/', $expr)) {
            throw new RuntimeException('Formula may only contain numbers and + - * / ( ).');
        }

        preg_match_all('/\d+\.?\d*|[+\-*\/()]/', $expr, $m);
        $tokens = $m[0];

        if (! $tokens) {
            throw new RuntimeException('Formula could not be parsed.');
        }

        $prec = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];
        $output = [];
        $ops = [];

        foreach ($tokens as $i => $token) {
            if (is_numeric($token)) {
                $output[] = (float) $token;

                continue;
            }

            if ($token === '-' && ($i === 0 || in_array($tokens[$i - 1], ['+', '-', '*', '/', '('], true))) {
                $output[] = 0.0;
                $ops[] = '-';

                continue;
            }

            if ($token === '(') {
                $ops[] = $token;

                continue;
            }

            if ($token === ')') {
                while ($ops && end($ops) !== '(') {
                    $output[] = array_pop($ops);
                }

                if (! $ops) {
                    throw new RuntimeException('Unbalanced parentheses in formula.');
                }

                array_pop($ops);

                continue;
            }

            while ($ops && end($ops) !== '(' && $prec[end($ops)] >= $prec[$token]) {
                $output[] = array_pop($ops);
            }

            $ops[] = $token;
        }

        while ($ops) {
            $op = array_pop($ops);

            if ($op === '(') {
                throw new RuntimeException('Unbalanced parentheses in formula.');
            }

            $output[] = $op;
        }

        $stack = [];

        foreach ($output as $token) {
            if (is_float($token)) {
                $stack[] = $token;

                continue;
            }

            if (count($stack) < 2) {
                throw new RuntimeException('Malformed formula.');
            }

            $b = array_pop($stack);
            $a = array_pop($stack);

            $stack[] = match ($token) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*' => $a * $b,
                '/' => $b == 0.0 ? 0.0 : $a / $b,
            };
        }

        if (count($stack) !== 1) {
            throw new RuntimeException('Malformed formula.');
        }

        return (float) $stack[0];
    }

    private function format(float $value, string $format, int $decimals): string
    {
        return match ($format) {
            'percent' => number_format($value, $decimals).'%',
            'currency' => '$'.number_format($value, $decimals),
            default => number_format($value, $decimals),
        };
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
