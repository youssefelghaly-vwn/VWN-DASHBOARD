<?php

namespace Tests\Feature;

use App\Integration\Models\Integration;
use App\Integration\Providers\Ghl\GhlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * searchOpportunities() must pull the WHOLE location, not GHL's first 20-row
 * page — the opportunities analogue of the contacts sync. These tests pin the
 * behaviours that make that reliable: following the meta cursor across pages,
 * stopping exactly when meta.total is reached, deduping boundary rows, and
 * still terminating when a page carries no cursor at all.
 */
class GhlOpportunitiesSearchTest extends TestCase
{
    use RefreshDatabase;

    private function integration(): Integration
    {
        return Integration::create([
            'provider' => 'gohighlevel',
            'name' => 'GHL',
            'status' => 'connected',
            'credentials' => ['access_token' => 'token', 'location_id' => 'loc1'],
        ]);
    }

    /**
     * The exact reported bug: GHL answers with a 20-row first page (ignoring our
     * bigger limit) and a cursor saying more exists. The old size heuristic
     * stopped at 20; searchOpportunities() must follow the cursor to the end.
     */
    public function test_it_follows_the_meta_cursor_past_the_first_short_page(): void
    {
        $base = config('integrations.gohighlevel.api_base');

        Http::fake([
            "{$base}/opportunities/search*" => Http::sequence()
                ->push([
                    'opportunities' => $this->opps('a', 20),
                    'meta' => ['total' => 45, 'startAfterId' => 'cur-1', 'startAfter' => 1000],
                ])
                ->push([
                    'opportunities' => $this->opps('b', 20),
                    'meta' => ['total' => 45, 'startAfterId' => 'cur-2', 'startAfter' => 2000],
                ])
                ->push([
                    'opportunities' => $this->opps('c', 5),
                    'meta' => ['total' => 45, 'startAfterId' => 'cur-3', 'startAfter' => 3000],
                ]),
        ]);

        $rows = app(GhlClient::class)->searchOpportunities($this->integration());

        $this->assertCount(45, $rows);
    }

    /** meta.total is authoritative: stop as soon as it's reached, no extra call. */
    public function test_it_stops_once_meta_total_is_reached(): void
    {
        $base = config('integrations.gohighlevel.api_base');

        Http::fake([
            "{$base}/opportunities/search*" => Http::sequence()
                ->push([
                    'opportunities' => $this->opps('a', 20),
                    'meta' => ['total' => 20, 'startAfterId' => 'cur-1', 'startAfter' => 1000],
                ])
                // Guard page — should never be requested.
                ->push(['opportunities' => $this->opps('x', 20), 'meta' => []]),
        ]);

        $rows = app(GhlClient::class)->searchOpportunities($this->integration());

        $this->assertCount(20, $rows);
        Http::assertSentCount(1);
    }

    /** A row re-served at a page boundary is deduped by id, not double counted. */
    public function test_it_dedupes_rows_that_repeat_across_pages(): void
    {
        $base = config('integrations.gohighlevel.api_base');

        Http::fake([
            "{$base}/opportunities/search*" => Http::sequence()
                ->push([
                    'opportunities' => [['id' => 'o1'], ['id' => 'o2'], ['id' => 'o3']],
                    'meta' => ['total' => 4, 'startAfterId' => 'o3', 'startAfter' => 1000],
                ])
                ->push([
                    // o3 repeats as the cursor boundary row.
                    'opportunities' => [['id' => 'o3'], ['id' => 'o4']],
                    'meta' => ['total' => 4, 'startAfterId' => 'o4', 'startAfter' => 2000],
                ]),
        ]);

        $rows = app(GhlClient::class)->searchOpportunities($this->integration());

        $this->assertCount(4, $rows);
        $this->assertSame(['o1', 'o2', 'o3', 'o4'], array_column($rows, 'id'));
    }

    /** With no cursor and no total, a short page is the last page — and we stop. */
    public function test_it_terminates_without_any_cursor(): void
    {
        $base = config('integrations.gohighlevel.api_base');

        Http::fake([
            "{$base}/opportunities/search*" => Http::response([
                'opportunities' => $this->opps('a', 7),
                'meta' => [],
            ]),
        ]);

        $rows = app(GhlClient::class)->searchOpportunities($this->integration());

        $this->assertCount(7, $rows);
        Http::assertSentCount(1);
    }

    /** @return array<int, array<string, string>> */
    private function opps(string $prefix, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['id' => $prefix.$i];
        }

        return $out;
    }
}
