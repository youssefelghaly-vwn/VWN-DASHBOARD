<?php

namespace App\Support;

/**
 * The one timezone every "what calendar day does this belong to"
 * computation in the app agrees on:
 *   - FiltersRows       — what date_today/date_this_week/etc. filters measure "today" against
 *   - CastsValues::date() — the Date column every integration provider normalizes into
 *   - CloudTalkProvider::fetchCalls() — the date_from/date_to window sent to CloudTalk's API
 *
 * Deliberately NOT config('app.timezone'): that's left at UTC on purpose so
 * every other timestamp in the app (created_at/updated_at, last_synced_at,
 * cache TTLs, ...) stays on the one clock Laravel/MySQL both assume by
 * default. Only "what day did this happen on" needs to follow Egypt's
 * calendar — and needs to do so consistently everywhere it's asked, not
 * wherever app.timezone happened to be set at that particular moment.
 */
final class BusinessTimezone
{
    public const NAME = 'Africa/Cairo';
}