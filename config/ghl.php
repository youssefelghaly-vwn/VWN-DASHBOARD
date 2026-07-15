<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GoHighLevel — Private Integration (API v2.0)
    |--------------------------------------------------------------------------
    */

    'api_base'    => env('GHL_API_BASE', 'https://services.leadconnectorhq.com'),
    'api_version' => env('GHL_API_VERSION', '2021-07-28'),

    // Pagination.
    'page_size'   => 100,
    'max_pages'   => 100,

    // HTTP timeouts (seconds). GHL's /calendars/events can be slow, so the
    // overall timeout is generous; connect timeout stays short to fail fast on
    // genuine network problems.
    'timeout'         => env('GHL_TIMEOUT', 30),
    'connect_timeout' => env('GHL_CONNECT_TIMEOUT', 10),

    // Appointments sweep controls — keep the calendar-events workload bounded.
    'max_calendars'      => env('GHL_MAX_CALENDARS', 15),   // calendars per sync
    'events_days_back'   => env('GHL_EVENTS_DAYS_BACK', 90),
    'events_days_forward'=> env('GHL_EVENTS_DAYS_FORWARD', 30),
];