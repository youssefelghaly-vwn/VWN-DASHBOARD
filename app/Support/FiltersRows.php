<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Applies a list of {column, operator, value} conditions to an array of rows,
 * ANDed together. Shared by MetricService (row-level filtering before an
 * aggregate) and DashboardData (row-level filtering before a chart groups
 * rows), so the same filter semantics apply everywhere.
 *
 * The operator vocabulary itself lives in FilterOperators.
 */
trait FiltersRows
{
    /**
     * The calendar the date_* filter operators (and the epoch-ms branch of
     * cellDate()) measure "today" against. See BusinessTimezone for why this
     * isn't config('app.timezone').
     */
    private const FILTER_TIMEZONE = BusinessTimezone::NAME;

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
        // Date windows are resolved ONCE, before the scan: every row has to be
        // measured against the same "today", and re-deriving the window per row
        // would also mean a Carbon construction per record.
        if (str_starts_with($operator, 'date_')) {
            return $this->applyDateFilter($rows, $column, $operator, $value);
        }

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
                // Deliberately not complements: a cell that is neither (blank,
                // "Unknown", free text) matches neither, the same way an
                // unclassifiable call is not counted as a miss.
                'is_true' => $this->truthiness($raw) === true,
                'is_false' => $this->truthiness($raw) === false,
                'not_empty' => $hay !== '',
                'empty' => $hay === '',
                default => true,
            };
        }));
    }

    /**
     * Read a cell as a boolean, or null when it does not state one.
     *
     * Only an explicit vocabulary counts — never "any non-empty string is
     * true", which would make `is true` match every populated text cell. A
     * value outside it (blank, "Unknown", a name) has no boolean answer, so it
     * matches neither is_true nor is_false.
     */
    private function truthiness(mixed $value): ?bool
    {
        // Checked before any string cast, because (string) false is '' — a real
        // boolean false would otherwise read as an empty cell.
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        $word = mb_strtolower(trim((string) $value));

        if ($word === '') {
            return null;
        }

        if (is_numeric($word)) {
            return (float) $word != 0.0;
        }

        return match ($word) {
            'true', 'yes', 'y', 'on' => true,
            'false', 'no', 'n', 'off' => false,
            default => null,
        };
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

    /**
     * Keep the rows whose $column falls inside the operator's day window.
     *
     * Cells that are empty or do not look like a date never match — a date
     * question about a blank cell has no true answer. An unusable window (the
     * admin picked "is on" but typed nothing) matches nothing rather than
     * everything, the same posture gt/lt already take with an unparseable
     * needle: a visible zero beats a plausible-looking unfiltered total.
     */
    private function applyDateFilter(array $rows, string $column, string $operator, mixed $value): array
    {
        [$from, $to] = $this->dateWindow($operator, (string) $value);

        if ($from === null && $to === null) {
            return [];
        }

        return array_values(array_filter($rows, function ($row) use ($column, $from, $to) {
            $day = $this->cellDate($row[$column] ?? '');

            if ($day === null) {
                return false;
            }

            return ($from === null || $day >= $from) && ($to === null || $day <= $to);
        }));
    }

    /**
     * The inclusive [from, to] day window an operator means right now. Either
     * end may be null for an open-ended window ("is before" has no floor);
     * both null means the operator could not be resolved at all.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    protected function dateWindow(string $operator, string $value): array
    {
        $today = CarbonImmutable::now(self::FILTER_TIMEZONE)->startOfDay();
        $n = $this->dayCount($value);
        $on = $this->cellDate($value);

        // "2026-09-01..2026-09-30" — a comma works too, since that is the
        // separator the other multi-value operators already use.
        [$start, $end] = array_pad(preg_split('/\s*(?:\.\.|,)\s*/', trim($value), 2), 2, null);

        return match ($operator) {
            'date_today' => [$today, $today],
            'date_yesterday' => [$today->subDay(), $today->subDay()],
            'date_this_week' => [$today->startOfWeek(), $today->endOfWeek()->startOfDay()],
            'date_this_month' => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
            'date_last_month' => [
                $today->subMonthNoOverflow()->startOfMonth(),
                $today->subMonthNoOverflow()->endOfMonth()->startOfDay(),
            ],
            // Both windows are exactly N days long and both include today.
            'date_last_n_days' => $n === null ? [null, null] : [$today->subDays($n - 1), $today],
            'date_next_n_days' => $n === null ? [null, null] : [$today, $today->addDays($n - 1)],
            'date_on' => [$on, $on],
            // Exclusive, so "before the 9th" does not quietly include the 9th.
            'date_before' => [null, $on?->subDay()],
            'date_after' => [$on?->addDay(), null],
            // One usable end is enough — the other side stays open.
            'date_between' => [$this->cellDate($start), $this->cellDate($end)],
            default => [null, null],
        };
    }

    /** The N of "last N days" — a positive whole number, or null if unusable. */
    private function dayCount(string $value): ?int
    {
        $n = $this->filterNumeric($value);

        return $n !== null && $n >= 1 ? (int) $n : null;
    }

    /**
     * A cell or filter bound read as a calendar day, or null when it does not
     * look like a date.
     *
     * Deliberately NOT Carbon::parse(): that reads "1st Email" as a day of the
     * month and "May" as a month, so a multi-select cell would land inside a
     * date range. Only the shapes our own data actually produces are accepted —
     * GHL sends ISO strings ("2026-09-09", "2026-08-24T18:25:36.233Z") or epoch
     * milliseconds, and CastsValues::date() normalizes to "Y-m-d".
     */
    protected function cellDate(mixed $v): ?CarbonImmutable
    {
        $s = trim((string) $v);

        if ($s === '') {
            return null;
        }

        // Epoch milliseconds (13 digits) or seconds (10), as GHL hands out on
        // some fields. Narrow digit counts on purpose: a bare integer column
        // should not start reading as a date.
        if (ctype_digit($s) && (strlen($s) === 13 || strlen($s) === 10)) {
            $ms = strlen($s) === 13 ? (int) $s : (int) $s * 1000;

            return CarbonImmutable::createFromTimestampMs($ms, self::FILTER_TIMEZONE)->startOfDay();
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ]|$)/', $s, $m)) {
            return $this->calendarDay((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // US-style, as a human might type into the "is on" box.
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:[T ]|$)#', $s, $m)) {
            return $this->calendarDay((int) $m[3], (int) $m[1], (int) $m[2]);
        }

        // "Sep 9, 2026" and "9 Sep 2026" — how a CRM's own UI writes a date, and
        // what lands in the cell if a field ever syncs as its display string.
        // A month NAME plus a day plus a four-digit year is unambiguous; the
        // bare "May" or "1st Email" that Carbon::parse would happily read as a
        // date matches neither pattern.
        if (preg_match('/^([a-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})$/i', $s, $m)) {
            $month = $this->monthNumber($m[1]);

            return $month ? $this->calendarDay((int) $m[3], $month, (int) $m[2]) : null;
        }

        if (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?\.?\s+([a-z]{3,9})\.?,?\s+(\d{4})$/i', $s, $m)) {
            $month = $this->monthNumber($m[2]);

            return $month ? $this->calendarDay((int) $m[3], $month, (int) $m[1]) : null;
        }

        return null;
    }

    /** 1-12 for an English month name or its three-letter prefix, else null. */
    private function monthNumber(string $name): ?int
    {
        $months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
        $index = array_search(mb_strtolower(substr($name, 0, 3)), $months, true);

        return $index === false ? null : $index + 1;
    }

    /**
     * Rejects a well-shaped but impossible date (2026-02-31) instead of
     * rolling it over.
     *
     * Explicit FILTER_TIMEZONE on purpose — matches dateWindow()'s $today,
     * which is built the same way. Without it, CarbonImmutable::create()
     * falls back to config('app.timezone') (UTC), while $today is Cairo —
     * a 3-hour gap between two things that need to be the same absolute
     * instant for date_today/date_yesterday's single-point [$today, $today]
     * window to ever match anything. date_this_week/date_this_month span
     * wide enough ranges that the same gap rarely pushed a row outside the
     * boundary, which is why only the single-day operators went to zero.
     */
    private function calendarDay(int $year, int $month, int $day): ?CarbonImmutable
    {
        return checkdate($month, $day, $year)
            ? CarbonImmutable::create($year, $month, $day, 0, 0, 0, self::FILTER_TIMEZONE)
            : null;
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