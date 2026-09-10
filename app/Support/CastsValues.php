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

    /**
     * See BusinessTimezone for why this trait doesn't use config('app.timezone').
     */
    private const DATE_TIMEZONE = BusinessTimezone::NAME;

    protected function date(mixed $v, bool $convertToBusinessTz = true): string
    {
        if (! $v) {
            return '';
        }

        try {
            $carbon = is_numeric($v)
                ? Carbon::createFromTimestampMs((int) $v)
                : Carbon::parse($v);

            // A string with its own explicit offset already states a
            // specific local day. Re-projecting it onto DATE_TIMEZONE is
            // only correct if that offset agrees with DATE_TIMEZONE's
            // current DST state — it doesn't always (see CloudTalkProvider,
            // which passes false: CloudTalk's own offset is the account's
            // ground truth there, not something to override).
            return $convertToBusinessTz
                ? $carbon->setTimezone(self::DATE_TIMEZONE)->toDateString()
                : $carbon->toDateString();
        } catch (\Throwable) {
            return $this->str($v);
        }
    }
}