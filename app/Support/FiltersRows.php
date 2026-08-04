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
                // Multi-value (array) fields: the cell is a comma-separated list
                // (e.g. Outreach Stages "1st Email, 1st Linked-IN") and so is the
                // needle. has_all = every needle token present; has_any = at
                // least one present. Order-independent, matched token-by-token.
                'has_all' => $this->listHasAll($raw, $needle),
                'has_any' => $this->listHasAny($raw, $needle),
                'not_has_any' => ! $this->listHasAny($raw, $needle),
                'not_empty' => $hay !== '',
                'empty' => $hay === '',
                default => true,
            };
        }));
    }

    /** Split a comma-separated cell/needle into lowercased, trimmed, non-empty tokens. */
    private function listTokens(mixed $v): array
    {
        $parts = array_map(
            fn ($p) => mb_strtolower(trim((string) $p)),
            explode(',', (string) $v)
        );

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    private function listHasAll(mixed $cell, mixed $needle): bool
    {
        $want = $this->listTokens($needle);

        if ($want === []) {
            return false;
        }

        return array_diff($want, $this->listTokens($cell)) === [];
    }

    private function listHasAny(mixed $cell, mixed $needle): bool
    {
        $want = $this->listTokens($needle);

        if ($want === []) {
            return false;
        }

        return array_intersect($want, $this->listTokens($cell)) !== [];
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
