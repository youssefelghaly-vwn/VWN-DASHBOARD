<?php

namespace App\Support;

/**
 * Thin public wrapper around FiltersRows for ad-hoc debugging.
 *
 * dateWindow()/applyOneFilter() are protected on the trait on purpose (so
 * metric/chart config can't smuggle in an arbitrary operator from outside
 * the model layer) — but a debug route needs the exact same computation a
 * real metric uses, not a hand-copied re-implementation that could quietly
 * drift from it. This class exists only to expose those two methods
 * publicly for that purpose.
 */
class DebugDateFilter
{
    use FiltersRows;

    /** @return array{0: ?\Carbon\CarbonImmutable, 1: ?\Carbon\CarbonImmutable} */
    public function window(string $operator, string $value = ''): array
    {
        return $this->dateWindow($operator, $value);
    }

    public function filter(array $rows, string $column, string $operator, string $value = ''): array
    {
        return $this->applyOneFilter($rows, $column, $operator, $value);
    }
}