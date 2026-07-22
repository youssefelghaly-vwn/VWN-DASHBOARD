<?php

namespace App\Support;

/**
 * Applies a list of {column, operator, value} conditions to an array of rows,
 * ANDed together. Shared by MetricService (row-level filtering before an
 * aggregate) and DashboardData (row-level filtering before a chart groups
 * rows), so the same filter semantics apply everywhere.
 */
trait FiltersRows
{
    protected function filterRows(array $rows, array $filters): array
    {
        foreach ($filters as $filter) {
            $column = $filter['column'] ?? null;
            $operator = $filter['operator'] ?? null;

            if (! $column || ! $operator) {
                continue;
            }

            $rows = $this->applyOneFilter($rows, (string) $column, (string) $operator, $filter['value'] ?? '');
        }

        return $rows;
    }

    protected function applyOneFilter(array $rows, string $column, string $operator, mixed $value): array
    {
        $needle = mb_strtolower(trim((string) $value));

        return array_values(array_filter($rows, function ($row) use ($column, $operator, $needle) {
            $raw = $row[$column] ?? '';
            $hay = mb_strtolower(trim((string) $raw));
            $num = $this->filterNumeric($raw);
            $need = $this->filterNumeric($needle);

            return match ($operator) {
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

    private function filterNumeric(mixed $v): ?float
    {
        if (is_numeric($v)) {
            return (float) $v;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $v);

        return $clean === '' || $clean === '-' ? null : (float) $clean;
    }
}
