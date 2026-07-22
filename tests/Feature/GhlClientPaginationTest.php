<?php

namespace Tests\Feature;

use App\Integration\Models\Integration;
use App\Integration\Providers\Ghl\GhlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression test for a real bug: paginate() was never sending the page-size
 * query param, so GHL's own (smaller) default page size was used, and the
 * loop then mistook that first short page for the last one — silently
 * truncating results (e.g. only 20 of 171 opportunities synced).
 */
class GhlClientPaginationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reproduces the real bug: the configured page_size (100) is never sent
     * as a query param when the caller omits `limitParam` (as
     * GoHighLevelProvider used to for /opportunities/search), so GHL falls
     * back to its own smaller default page. The old loop compared that
     * page's size to our *assumed* pageSize (100), decided a 20-row page
     * "wasn't full", and stopped — even though the response's own cursor
     * said more data existed. Only the first page (20 of 25 rows) synced.
     */
    public function test_paginate_follows_the_cursor_even_when_the_actual_page_is_smaller_than_configured(): void
    {
        $integration = Integration::create([
            'provider' => 'gohighlevel',
            'name' => 'GHL',
            'status' => 'connected',
            'credentials' => ['access_token' => 'token', 'location_id' => 'loc1'],
        ]);

        $base = config('integrations.gohighlevel.api_base');
        $this->assertSame(100, config('integrations.gohighlevel.page_size'));

        Http::fake([
            "{$base}/opportunities/search*" => Http::sequence()
                ->push([
                    'opportunities' => array_fill(0, 20, ['id' => 'a']),
                    'meta' => ['startAfterId' => 'cursor-1', 'startAfter' => 1000],
                ])
                ->push([
                    'opportunities' => array_fill(0, 5, ['id' => 'b']),
                    'meta' => [],
                ]),
        ]);

        // No `limitParam` opt — the exact call shape GoHighLevelProvider used
        // to make before the fix.
        $rows = app(GhlClient::class)->paginate(
            $integration,
            '/opportunities/search',
            'opportunities',
            ['location_id' => 'loc1']
        );

        $this->assertCount(25, $rows);
    }
}
