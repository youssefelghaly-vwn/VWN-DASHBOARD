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

            // Always convert to DATE_TIMEZONE: a raw offset embedded in the
            // source value (a "Z", a "+02:00", ...) states an instant, not
            // necessarily the calendar day the business considers that
            // instant to fall on. CloudTalk is the confirmed example — its
            // API always wire-serializes in a fixed +02:00, but the
            // account's real clock (matching both its dashboard and its own
            // date_from/date_to filtering) is Africa/Cairo, DST included,
            // proven by a call the dashboard showed as "Sep 9, 12:55 AM"
            // whose raw answered_at was "...T23:55:37+02:00" the day
            // before — only the Cairo conversion lands on that time.
            // $convertToBusinessTz exists as an escape hatch for some future
            // source that turns out to need the opposite; CloudTalkProvider
            // tried that for exactly this field and it was wrong, so nothing
            // currently calls date() with false.
            return $convertToBusinessTz
                ? $carbon->setTimezone(self::DATE_TIMEZONE)->toDateString()
                : $carbon->toDateString();
        } catch (\Throwable) {
            return $this->str($v);
        }
    }
}