<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Small value-normalization helpers shared by providers when shaping external
 * API responses into flat display rows. Extracted from the original GHL service
 * so every provider produces consistently typed columns (strings, numbers,
 * ISO dates) for the aggregators.
 */
trait CastsValues
{
    protected function str(mixed $v): string
    {
        if (is_array($v)) {
            $flat = array_map(
                fn ($x) => is_scalar($x) ? (string) $x : json_encode($x),
                $v
            );

            return implode(', ', array_filter($flat, fn ($x) => $x !== ''));
        }

        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        return $v === null ? '' : (string) $v;
    }

    protected function num(mixed $v): float|int|string
    {
        if (is_numeric($v)) {
            return $v + 0;
        }

        return $this->str($v);
    }

    protected function date(mixed $v): string
    {
        if (! $v) {
            return '';
        }

        try {
            return is_numeric($v)
                ? Carbon::createFromTimestampMs((int) $v)->toDateString()
                : Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return $this->str($v);
        }
    }
}
