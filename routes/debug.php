<?php

use Illuminate\Support\Facades\Route;
use App\Integration\Services\RecordReader;
use App\Support\DebugDateFilter;
 
use App\Integration\Models\Integration;
use App\Integration\Providers\DebugCloudTalkProvider;


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
















