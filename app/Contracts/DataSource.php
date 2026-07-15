<?php

namespace App\Contracts;

use App\Models\Chart;

/**
 * The single surface every data source exposes to the rest of the app.
 *
 * Google Sheets and GoHighLevel both implement this. Because the chart/metric
 * builders, controllers, and buildChart() only ever talk to this interface,
 * swapping the active source is a config decision, not a code change.
 *
 * The mental model: every source presents its data as a set of named "sheets",
 * each being a list of associative rows: ['SheetName' => [ ['col' => val], ... ]].
 * For Sheets these are literal tabs; for GHL they're virtual sheets
 * (Opportunities, Contacts, Appointments, Users) built from API resources.
 */
interface DataSource
{
    /** ['SheetName' => [ ['col' => val, ...], ... ], ...] — every sheet, cached. */
    public function all(bool $fresh = false): array;

    /** Rows for one named sheet. */
    public function sheet(string $name, bool $fresh = false): array;

    /** Column names available in a sheet (drives the builder dropdowns). */
    public function columns(string $name): array;

    /** ['SheetName' => ['col1','col2'], ...] — the full schema for the builder UI. */
    public function schema(): array;

    /**
     * Group rows by a label column, aggregating a value column.
     * Returns ['labels' => [...], 'values' => [...]].
     */
    public function aggregate(
        array $rows,
        string $labelColumn,
        ?string $valueColumn = null,
        string $agg = 'count',
        int $limit = 10
    ): array;

    /** Build a Chart.js-ready payload for a saved Chart model. */
    public function buildChart(Chart $chart): array;

    /** Human label for the connected source, shown in the sidebar/settings. */
    public function label(): string;
}