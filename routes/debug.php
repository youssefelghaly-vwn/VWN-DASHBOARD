<?php

use Illuminate\Support\Facades\Route;
use App\Integration\Services\RecordReader;
use App\Support\DebugDateFilter;
 
use App\Integration\Models\Integration;
use App\Integration\Providers\DebugCloudTalkProvider;
use App\Dashboard\Models\Metric;
use App\Metric\Services\MetricService;
use App\Support\BusinessTimezone;
use Illuminate\Support\Facades\DB;

Route::get('/debug/cloudtalk-yesterday', function (RecordReader $reader, DebugDateFilter $debug) {
    $integrationId = 12; // CloudTalk — matches the id used throughout this dashboard's SQL
    $dataset = 'Calls';
 
    // The exact same source every metric/chart reads from — never a fresh
    // API call, so this shows precisely what the filters operate on.
    $rows = $reader->rows($integrationId, $dataset);
 
    $todayWindow = $debug->window('date_today');
    $yesterdayWindow = $debug->window('date_yesterday');
    $yesterdayRows = $debug->filter($rows, 'Date', 'date_yesterday');
 
    dd([
        'server_clock' => [
            'date_default_timezone_get()' => date_default_timezone_get(),
            "config('app.timezone')" => config('app.timezone'),
            'now()' => now()->toDateTimeString().' ('.now()->timezoneName.')',
            "now('Africa/Cairo')" => now('Africa/Cairo')->toDateTimeString(),
        ],
        // What FiltersRows::FILTER_TIMEZONE resolves "today"/"yesterday" to,
        // right now, on this server — this is the actual boundary every
        // date_today/date_yesterday/etc. filter measures against.
        'today_window_[from,to]' => array_map(fn ($b) => $b?->toDateTimeString(), $todayWindow),
        'yesterday_window_[from,to]' => array_map(fn ($b) => $b?->toDateTimeString(), $yesterdayWindow),
 
        'total_rows_in_Calls_dataset' => count($rows),
        'rows_matching_date_yesterday' => count($yesterdayRows),
 
        'sample_raw_rows' => array_slice($rows, 0, 5),
        'all_yesterday_rows' => $yesterdayRows,
    ]);
})->name('debug.cloudtalk-yesterday');
 

 
Route::get('/debug/cloudtalk-transform', function (DebugCloudTalkProvider $provider) {
    $integration = Integration::where('provider', 'cloudtalk')->firstOrFail();
 
    // Live fetch — bypasses the DB and any worker/opcache staleness entirely,
    // since this runs the actual current code in this actual request.
    $rawCalls = $provider->debugFetchCalls($integration);
    $sample = array_slice($rawCalls, 0, 3);
 
    // Agents map only affects the 'Agent'/'Agent ID'/'Campaigns' columns —
    // passing [] is fine for inspecting Date/Time/Answered, which is the point here.
    $stored = $provider->debugCallRows($sample, []);
 
    dd([
        'total_calls_fetched' => count($rawCalls),
        'raw_from_api' => $sample,
        'stored_after_transform' => $stored,
    ]);
})->name('debug.cloudtalk-transform');





Route::get('/debug/cloudtalk-dashboard-render', function (
    RecordReader $reader,
    MetricService $metricService
) {
    $integrationId = 12; // CloudTalk

    // 1. Raw DB state, read fresh in THIS request — no browser, no opcache,
    //    no prior process's memory involved at all.
    $rawDistribution = DB::table('integration_records')
        ->where('integration_id', $integrationId)
        ->where('dataset', 'Calls')
        ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.Date')) as date_field, COUNT(*) as calls, MAX(created_at) as synced_at")
        ->groupBy('date_field')
        ->orderByDesc('date_field')
        ->get();

    // 2. What RecordReader — the one thing every metric actually reads
    //    from — returns right now, in this same live process.
    $rows = $reader->rows($integrationId, 'Calls');

    // 3. Every CloudTalk "Calls" metric currently defined in the DB, exactly
    //    as stored — so we can see the real filter_column/operator/filters,
    //    not what we assume they are.
    $metrics = Metric::where('integration_id', $integrationId)
        ->where('sheet', 'Calls')
        ->orderBy('section_id')
        ->orderBy('position')
        ->get();

    // 4. Run each one through the REAL MetricService::computeSimple() —
    //    the exact class and method the dashboard itself calls to render
    //    every card. If this number is right but the dashboard still shows
    //    0, the bug is downstream of this (rendering/caching). If this
    //    number is ALSO wrong, the bug is in the metric's own stored
    //    config, not the sync or the timezone logic.
    $rendered = $metrics->map(function (Metric $m) use ($metricService) {
        $cfg = [
            'integration_id' => $m->integration_id,
            'sheet' => $m->sheet,
            'agg' => $m->agg,
            'column' => $m->column,
            'filter_column' => $m->filter_column,
            'filter_operator' => $m->filter_operator,
            'filter_value' => $m->filter_value,
            'filters' => $m->filters ?? [],
        ];

        return [
            'metric_id' => $m->id,
            'title' => $m->title,
            'subtitle' => $m->subtitle,
            'section_id' => $m->section_id,
            'stored_config' => $cfg,
            'live_computed_value' => $metricService->computeSimple($cfg),
        ];
    });

    dd([
        'business_timezone_right_now' => BusinessTimezone::NAME,
        'server_now' => now()->toDateTimeString().' ('.now()->timezoneName.')',
        'raw_db_date_distribution' => $rawDistribution,
        'record_reader_total_rows_this_request' => count($rows),
        'cloudtalk_calls_metrics' => $rendered,
    ]);
})->name('debug.cloudtalk-dashboard-render');
















