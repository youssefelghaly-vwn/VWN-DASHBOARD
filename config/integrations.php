<?php

use App\Integration\Providers\CloudTalkProvider;
use App\Integration\Providers\GoHighLevelProvider;
use App\Integration\Providers\GoogleSheetsProvider;
use App\Integration\Providers\MetaAdsProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | The complete catalogue of integrations the app knows how to run. Adding a
    | new integration is a matter of writing a provider class and adding one
    | line here — nothing else in the app needs to change.
    |
    | key => provider class (implements App\Integration\Providers\IntegrationProvider)
    */

    'providers' => [
        'gohighlevel' => GoHighLevelProvider::class,
        'google_sheets' => GoogleSheetsProvider::class,
        'meta_ads' => MetaAdsProvider::class,
        'cloudtalk' => CloudTalkProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-provider configuration
    |--------------------------------------------------------------------------
    | Each provider reads its own sub-array. Keep provider-specific tuning here
    | so the provider classes stay free of magic numbers.
    */

    'gohighlevel' => [
        'api_base' => env('GHL_API_BASE', 'https://services.leadconnectorhq.com'),
        'api_version' => env('GHL_API_VERSION', '2021-07-28'),

        'page_size' => 100,
        'max_pages' => 100,

        'timeout' => env('GHL_TIMEOUT', 30),
        'connect_timeout' => env('GHL_CONNECT_TIMEOUT', 10),
        'retries' => env('GHL_RETRIES', 2),

        // Appointments sweep controls — keep the calendar-events workload bounded.
        'max_calendars' => env('GHL_MAX_CALENDARS', 15),
        'events_days_back' => env('GHL_EVENTS_DAYS_BACK', 90),
        'events_days_forward' => env('GHL_EVENTS_DAYS_FORWARD', 30),
    ],

    'cloudtalk' => [
        // CloudTalk core API. The Dialer partner API (api.cloudtalk.io/v1) is a
        // different surface with different auth; this provider speaks the core one.
        'api_base' => env('CLOUDTALK_API_BASE', 'https://my.cloudtalk.io/api'),

        // Cloudflare fronts *.cloudtalk.io and answers default library user
        // agents with a bot challenge (error 1010) instead of the API.
        'user_agent' => env('CLOUDTALK_USER_AGENT', 'VWN-Dashboard/1.0'),

        'timeout' => env('CLOUDTALK_TIMEOUT', 30),
        'connect_timeout' => env('CLOUDTALK_CONNECT_TIMEOUT', 10),
        'retries' => env('CLOUDTALK_RETRIES', 2),

        // The account-wide rate limit is 60 requests/minute shared across every
        // key, so page in bounded batches rather than racing through history.
        'page_size' => 100,
        'max_pages' => env('CLOUDTALK_MAX_PAGES', 20),

        // How far back each sync pulls call history. Every call is stored
        // locally and a dataset is read into memory whole, so widen this
        // deliberately — 30 days of a busy dialer is already a lot of rows.
        'days_back' => env('CLOUDTALK_DAYS_BACK', 30),
    ],

    'meta_ads' => [
        // Meta Marketing (Graph) API.
        'api_base' => env('META_API_BASE', 'https://graph.facebook.com'),
        'api_version' => env('META_API_VERSION', 'v21.0'),

        'timeout' => env('META_TIMEOUT', 30),
        'connect_timeout' => env('META_CONNECT_TIMEOUT', 10),
        'retries' => env('META_RETRIES', 2),
        'page_size' => 100,
        'max_pages' => 100,

        // How far back to pull daily performance insights on each sync.
        'insights_days_back' => env('META_INSIGHTS_DAYS_BACK', 90),
    ],

];
