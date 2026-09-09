<?php

namespace App\Support;

/**
 * The one list of filter operators the app knows.
 *
 * It used to live as a copy-pasted `const OPS` in every controller plus a
 * hand-written <option> list in every builder, which is why adding an operator
 * meant editing five files and forgetting one. Controllers validate against
 * keys(), the builders render groups(), and FiltersRows implements them.
 *
 * `input` says what the value box should collect, so the UI can swap a text box
 * for a date picker (or hide it) instead of asking an admin to type a range by
 * hand: none | text | number | date | date_range.
 */
final class FilterOperators
{
    /** @var array<string, array{label: string, input: string, group: string}> */
    public const ALL = [
        'eq' => ['label' => 'equals', 'input' => 'text', 'group' => 'Text'],
        'neq' => ['label' => 'does not equal', 'input' => 'text', 'group' => 'Text'],
        'contains' => ['label' => 'contains', 'input' => 'text', 'group' => 'Text'],
        'not_contains' => ['label' => 'does not contain', 'input' => 'text', 'group' => 'Text'],
        'not_empty' => ['label' => 'is not empty', 'input' => 'none', 'group' => 'Text'],
        'empty' => ['label' => 'is empty', 'input' => 'none', 'group' => 'Text'],

        'gt' => ['label' => 'greater than', 'input' => 'number', 'group' => 'Number'],
        'lt' => ['label' => 'less than', 'input' => 'number', 'group' => 'Number'],

        // Boolean columns are spelled differently by every source — "Yes"/"No"
        // from a provider's own ternary, "true"/"false" from CastsValues::str()
        // on a real bool, 1/0 from a spreadsheet — so these ask the question
        // instead of making an admin guess which spelling landed in the cell.
        'is_true' => ['label' => 'is true (yes / 1)', 'input' => 'none', 'group' => 'Yes / No'],
        'is_false' => ['label' => 'is false (no / 0)', 'input' => 'none', 'group' => 'Yes / No'],

        'has_all' => ['label' => 'has all of (comma-sep)', 'input' => 'text', 'group' => 'List'],
        'has_any' => ['label' => 'has any of (comma-sep)', 'input' => 'text', 'group' => 'List'],
        'not_has_any' => ['label' => 'has none of (comma-sep)', 'input' => 'text', 'group' => 'List'],

        // Relative windows resolve against "today" at read time, so a metric
        // built once keeps meaning the same thing tomorrow.
        'date_today' => ['label' => 'is today', 'input' => 'none', 'group' => 'Date'],
        'date_yesterday' => ['label' => 'is yesterday', 'input' => 'none', 'group' => 'Date'],
        'date_last_n_days' => ['label' => 'in the last N days', 'input' => 'number', 'group' => 'Date'],
        'date_next_n_days' => ['label' => 'in the next N days', 'input' => 'number', 'group' => 'Date'],
        'date_this_week' => ['label' => 'is this week', 'input' => 'none', 'group' => 'Date'],
        'date_this_month' => ['label' => 'is this month', 'input' => 'none', 'group' => 'Date'],
        'date_last_month' => ['label' => 'is last month', 'input' => 'none', 'group' => 'Date'],
        'date_on' => ['label' => 'is on', 'input' => 'date', 'group' => 'Date'],
        'date_before' => ['label' => 'is before', 'input' => 'date', 'group' => 'Date'],
        'date_after' => ['label' => 'is after', 'input' => 'date', 'group' => 'Date'],
        'date_between' => ['label' => 'is between', 'input' => 'date_range', 'group' => 'Date'],
    ];

    /** Separates the two halves of a date_between value ("2026-09-01..2026-09-30"). */
    public const RANGE_SEPARATOR = '..';

    /** @return array<int, string> every operator key, for `in:` validation */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function has(string $operator): bool
    {
        return isset(self::ALL[$operator]);
    }

    /** @return array<string, array<string, string>> group => [key => label], for <optgroup> */
    public static function groups(): array
    {
        $out = [];

        foreach (self::ALL as $key => $spec) {
            $out[$spec['group']][$key] = $spec['label'];
        }

        return $out;
    }

    /** @return array<string, string> key => input kind, handed to the builders' JS */
    public static function inputKinds(): array
    {
        return array_map(fn (array $spec) => $spec['input'], self::ALL);
    }
}
