<?php

namespace Tests\Feature;

use App\Integration\Models\Integration;
use App\Integration\Providers\CloudTalkProvider;
use App\Integration\Services\IntegrationManager;
use App\Integration\Services\SyncService;
use App\Metric\Services\MetricService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CloudTalk agent performance.
 *
 * The response shapes here mirror what the account actually returns: the
 * {"responseData": {...}} envelope, entity-wrapped rows ({"Agent": {...}}),
 * a roster `name` field holding a device label rather than a person, and an
 * `availability_status` sitting alongside the real per-call `status`. Each of
 * those has a way of quietly producing a plausible-but-wrong number, so each
 * gets a test.
 */
class CloudTalkIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-09-09 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY);
        config(['integrations.cloudtalk.days_back' => 30]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function integration(array $config = []): Integration
    {
        return Integration::create([
            'provider' => 'cloudtalk',
            'name' => 'CloudTalk',
            'status' => 'connected',
            'credentials' => ['access_key_id' => 'key', 'access_key_secret' => 'secret'],
            'config' => $config ?: ['datasets' => CloudTalkProvider::DATASETS],
        ]);
    }

    /** The core API's envelope, for one page of rows. */
    private function page(array $data, ?int $total = null): array
    {
        return ['responseData' => [
            'data' => $data,
            'itemsCount' => $total ?? count($data),
            'pageCount' => 1,
            'page' => 1,
        ]];
    }

    private function fakeCloudTalk(?array $calls = null): void
    {
        $base = config('integrations.cloudtalk.api_base');

        Http::fake([
            "{$base}/agents/index.json*" => Http::response($this->page([
                // `name` is a device/extension label on this account, not a person.
                ['Agent' => ['id' => 101, 'name' => '362284011005', 'firstname' => 'Nada', 'lastname' => 'Ahmed', 'email' => 'nada@vwn.io']],
                ['Agent' => ['id' => 102, 'name' => '362284011006', 'firstname' => 'Shevin', 'lastname' => 'Pollydore', 'email' => 's@vwn.io']],
                // On no campaign and, below, no calls — the agent only a roster pull can surface.
                ['Agent' => ['id' => 103, 'name' => '362284011007', 'firstname' => 'Idle', 'lastname' => 'Agent', 'email' => 'idle@vwn.io']],
            ])),
            "{$base}/campaigns/index.json*" => Http::response($this->page([
                [
                    'Campaign' => ['id' => 7, 'name' => 'HIPAA Outbound', 'status' => 'active', 'is_predictive' => true, 'attempts' => 3],
                    'Agent' => [['id' => 101, 'firstname' => 'Nada', 'lastname' => 'Ahmed'], ['id' => 102, 'firstname' => 'Shevin', 'lastname' => 'Pollydore']],
                ],
            ])),
            "{$base}/calls/index.json*" => Http::response($this->page($calls ?? $this->defaultCalls())),
        ]);
    }

    private function defaultCalls(): array
    {
        return [
            // Nada, today: one answered, one missed.
            ['Cdr' => ['id' => 1, 'agent_id' => 101, 'started_at' => '2026-09-09 09:15:00', 'status' => 'answered', 'talking_time' => 180, 'type' => 'outbound', 'availability_status' => 'online']],
            ['Cdr' => ['id' => 2, 'agent_id' => 101, 'started_at' => '2026-09-09 09:40:00', 'status' => 'not answered', 'talking_time' => 0, 'type' => 'outbound', 'availability_status' => 'online']],
            // Nada, yesterday.
            ['Cdr' => ['id' => 3, 'agent_id' => 101, 'started_at' => '2026-09-08 14:00:00', 'status' => 'answered', 'talking_time' => 300, 'type' => 'outbound', 'availability_status' => 'online']],
            // Shevin, today.
            ['Cdr' => ['id' => 4, 'agent_id' => 102, 'started_at' => '2026-09-09 11:05:00', 'status' => 'answered', 'talking_time' => 60, 'type' => 'inbound', 'availability_status' => 'offline']],
            // An agent in neither the roster nor a campaign.
            ['Cdr' => ['id' => 5, 'agent_id' => 999, 'started_at' => '2026-09-09 12:00:00', 'status' => 'answered', 'talking_time' => 30, 'type' => 'inbound']],
        ];
    }

    private function rows(Integration $integration, string $dataset): array
    {
        return $integration->rows($dataset);
    }

    /* ===================== the plumbing ===================== */

    public function test_the_provider_is_registered_like_every_other(): void
    {
        $manager = app(IntegrationManager::class);

        $this->assertContains('cloudtalk', $manager->keys());
        $this->assertSame('CloudTalk', $manager->get('cloudtalk')->label());
    }

    public function test_connect_validates_the_key_pair_and_stores_it_encrypted(): void
    {
        $this->fakeCloudTalk();

        $integration = new Integration(['provider' => 'cloudtalk']);
        app(IntegrationManager::class)->get('cloudtalk')->connect($integration, [
            'access_key_id' => 'key',
            'access_key_secret' => 'secret',
        ]);

        $this->assertSame('connected', $integration->status);
        $this->assertSame('key', $integration->credential('access_key_id'));

        // credentials use an encrypted cast, so read the raw column, under it
        $stored = (string) DB::table('integrations')->where('id', $integration->id)->value('credentials');
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('secret', $stored);
    }

    public function test_connect_refuses_an_incomplete_key_pair(): void
    {
        $this->expectExceptionMessage('Access Key ID and Access Key Secret are required');

        app(IntegrationManager::class)->get('cloudtalk')
            ->connect(new Integration(['provider' => 'cloudtalk']), ['access_key_id' => 'key']);
    }

    public function test_credentials_travel_as_basic_auth_with_a_real_user_agent(): void
    {
        $this->fakeCloudTalk();
        app(SyncService::class)->run($this->integration());

        Http::assertSent(function ($request) {
            // Cloudflare answers a default library UA with a bot challenge.
            $this->assertNotEmpty($request->header('User-Agent')[0] ?? '');
            $this->assertStringNotContainsString('Guzzle', $request->header('User-Agent')[0]);

            return str_starts_with($request->header('Authorization')[0] ?? '', 'Basic ');
        });
    }

    public function test_call_history_is_pulled_for_the_configured_window(): void
    {
        config(['integrations.cloudtalk.days_back' => 7]);
        $this->fakeCloudTalk();

        app(SyncService::class)->run($this->integration());

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/calls/index.json')
            || (str_contains(urldecode($request->url()), 'date_from=2026-09-03 00:00:00')
                && str_contains(urldecode($request->url()), 'date_to=2026-09-09 23:59:59')));
    }

    /* ===================== agents ===================== */

    public function test_agents_carry_their_person_name_not_the_device_label(): void
    {
        $this->fakeCloudTalk();
        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        $agents = collect($this->rows($integration, 'Agents'))->keyBy('Agent ID');

        $this->assertSame('Nada Ahmed', $agents['101']['Agent']);
        $this->assertSame('nada@vwn.io', $agents['101']['Email']);
        // The roster's `name` is an extension string; it must never become the label.
        $this->assertNotSame('362284011005', $agents['101']['Agent']);
    }

    /** The whole reason the roster is fetched separately from campaigns and calls. */
    public function test_an_agent_with_no_campaign_and_no_calls_still_gets_a_row(): void
    {
        $this->fakeCloudTalk();
        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        $agents = collect($this->rows($integration, 'Agents'))->keyBy('Agent ID');

        $this->assertArrayHasKey('103', $agents);
        $this->assertSame('Idle Agent', $agents['103']['Agent']);
        $this->assertSame(0, $agents['103']['Calls']);
        $this->assertSame('No', $agents['103']['On A Campaign']);
    }

    public function test_an_agent_seen_only_on_a_call_still_gets_a_row(): void
    {
        $this->fakeCloudTalk();
        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        $agents = collect($this->rows($integration, 'Agents'))->keyBy('Agent ID');

        $this->assertArrayHasKey('999', $agents);
        $this->assertSame(1, $agents['999']['Calls']);
    }

    public function test_agent_totals_and_campaign_membership(): void
    {
        $this->fakeCloudTalk();
        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        $nada = collect($this->rows($integration, 'Agents'))->firstWhere('Agent ID', '101');

        $this->assertSame('HIPAA Outbound', $nada['Campaigns']);
        $this->assertSame(3, $nada['Calls']);
        $this->assertSame(2, $nada['Answered']);
        $this->assertSame(1, $nada['Not Answered']);
        $this->assertEquals(66.7, $nada['Answer Rate']);
        $this->assertEquals(8, $nada['Talk Time']);        // 480s in minutes
        $this->assertEquals(2.7, $nada['Avg Talk Time']); // 480s / 3 calls
    }

    /**
     * `availability_status` is the agent's online state, identical on every one
     * of their calls. Reading it as the per-call outcome makes answer rates
     * silently wrong, so only a field named exactly `status` may be the outcome.
     */
    public function test_availability_status_is_never_read_as_the_call_outcome(): void
    {
        $this->fakeCloudTalk([
            ['Cdr' => ['id' => 1, 'agent_id' => 101, 'started_at' => '2026-09-09 09:00:00', 'status' => 'not answered', 'talking_time' => 0, 'availability_status' => 'answered']],
        ]);

        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        $call = $this->rows($integration, 'Calls')[0];
        $this->assertSame('No', $call['Answered']);
        $this->assertSame('not answered', $call['Status']);
    }

    /** No status field at all: fall back to talk time, and say so honestly when even that is absent. */
    public function test_answered_falls_back_to_talk_time_and_admits_when_it_cannot_tell(): void
    {
        $this->fakeCloudTalk([
            ['Cdr' => ['id' => 1, 'agent_id' => 101, 'started_at' => '2026-09-09 09:00:00', 'talking_time' => 45]],
            ['Cdr' => ['id' => 2, 'agent_id' => 101, 'started_at' => '2026-09-09 09:10:00', 'talking_time' => 0]],
            ['Cdr' => ['id' => 3, 'agent_id' => 101, 'started_at' => '2026-09-09 09:20:00']],
        ]);

        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        $calls = collect($this->rows($integration, 'Calls'))->pluck('Answered')->all();
        $this->assertSame(['Yes', 'No', 'Unknown'], $calls);

        // An unclassifiable call must not be counted as a miss in the rate.
        $agent = collect($this->rows($integration, 'Agents'))->firstWhere('Agent ID', '101');
        $this->assertSame(3, $agent['Calls']);
        $this->assertSame(1, $agent['Unclassified']);
        $this->assertEquals(50, $agent['Answer Rate']);
    }

    /* ===================== performance by date ===================== */

    public function test_agent_daily_is_one_row_per_agent_per_day(): void
    {
        $this->fakeCloudTalk();
        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        $daily = collect($this->rows($integration, 'Agent Daily'));

        $today = $daily->firstWhere(fn ($r) => $r['Agent ID'] === '101' && $r['Date'] === '2026-09-09');
        $this->assertSame(2, $today['Calls']);
        $this->assertSame(1, $today['Answered']);
        $this->assertEquals(50, $today['Answer Rate']);

        $yesterday = $daily->firstWhere(fn ($r) => $r['Agent ID'] === '101' && $r['Date'] === '2026-09-08');
        $this->assertSame(1, $yesterday['Calls']);
        $this->assertEquals(5, $yesterday['Talk Time']);

        // Nada has two days, Shevin one, the call-only agent one; the idle agent has none.
        $this->assertCount(4, $daily);
    }

    /** The end-to-end ask: an agent's calls over a date window, as a metric. */
    public function test_a_metric_counts_an_agents_calls_in_a_date_window(): void
    {
        $this->fakeCloudTalk();
        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        $callsToday = app(MetricService::class)->computeSimple([
            'integration_id' => $integration->id,
            'sheet' => 'Calls',
            'agg' => 'count_if',
            'filters' => [
                ['column' => 'Agent', 'operator' => 'eq', 'value' => 'Nada Ahmed'],
                ['column' => 'Date', 'operator' => 'date_today', 'value' => ''],
            ],
        ]);

        $this->assertSame(2.0, $callsToday);

        // Summed off the daily rollup over a window, rather than per call.
        $talkThisWeek = app(MetricService::class)->computeSimple([
            'integration_id' => $integration->id,
            'sheet' => 'Agent Daily',
            'agg' => 'sum',
            'column' => 'Talk Time',
            'filters' => [
                ['column' => 'Agent', 'operator' => 'eq', 'value' => 'Nada Ahmed'],
                ['column' => 'Date', 'operator' => 'date_last_n_days', 'value' => '7'],
            ],
        ]);

        $this->assertSame(8.0, $talkThisWeek);
    }

    /* ===================== failure behaviour ===================== */

    /**
     * A dataset whose source failed must keep its last good rows.
     *
     * write() replaces a dataset wholesale, so writing an empty result after a
     * failed fetch would both delete real data and record the dataset as
     * healthy — a transient 500 would quietly empty every dashboard reading it.
     */
    public function test_a_failing_fetch_leaves_the_previous_rows_intact(): void
    {
        $this->fakeCloudTalk();

        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        $goodAgents = $this->rows($integration, 'Agents');
        $goodCalls = $this->rows($integration, 'Calls');
        $this->assertNotEmpty($goodAgents);
        $this->assertNotEmpty($goodCalls);

        // Now call history starts failing.
        $base = config('integrations.cloudtalk.api_base');
        Http::fake([
            "{$base}/agents/index.json*" => Http::response($this->page([
                ['Agent' => ['id' => 101, 'firstname' => 'Nada', 'lastname' => 'Ahmed']],
            ])),
            "{$base}/campaigns/index.json*" => Http::response($this->page([])),
            "{$base}/calls/index.json*" => Http::response(['message' => 'Server error'], 500),
        ]);

        $run = app(SyncService::class)->run($integration);

        $this->assertEquals($goodAgents, $this->rows($integration, 'Agents'));
        $this->assertEquals($goodCalls, $this->rows($integration, 'Calls'));
    }

    /**
     * Agents falls with Calls on purpose: its rows carry call-derived
     * performance columns, so writing them from an empty fetch would replace
     * real figures with zeros everywhere they are charted. Campaigns, which
     * depends on nothing that failed, still syncs.
     */
    public function test_a_failed_call_fetch_marks_every_dataset_built_from_it(): void
    {
        $base = config('integrations.cloudtalk.api_base');

        Http::fake([
            "{$base}/agents/index.json*" => Http::response($this->page([
                ['Agent' => ['id' => 101, 'firstname' => 'Nada', 'lastname' => 'Ahmed']],
            ])),
            "{$base}/campaigns/index.json*" => Http::response($this->page([
                ['Campaign' => ['id' => 7, 'name' => 'HIPAA Outbound', 'status' => 'active'], 'Agent' => []],
            ])),
            "{$base}/calls/index.json*" => Http::response(['message' => 'Server error'], 500),
        ]);

        $integration = $this->integration();
        $datasets = app(SyncService::class)->run($integration)->meta['datasets'];

        $this->assertTrue($datasets['Campaigns']['ok']);
        $this->assertNotEmpty($this->rows($integration, 'Campaigns'));

        foreach (['Calls', 'Agents', 'Agent Daily'] as $dataset) {
            $this->assertFalse($datasets[$dataset]['ok'], "{$dataset} should be reported failed");
            $this->assertEmpty($this->rows($integration, $dataset));
        }
    }

    public function test_a_bad_key_reports_a_useful_message(): void
    {
        $base = config('integrations.cloudtalk.api_base');
        Http::fake(["{$base}/*" => Http::response(['message' => 'Unauthorized'], 401)]);

        $integration = $this->integration();
        $run = app(SyncService::class)->run($integration);

        $this->assertStringContainsString('rejected the credentials', $run->last_error);
        $this->assertStringContainsString('Admin user', $run->last_error);
    }

    /** Cloudflare's bot challenge is a full HTML page; it must not be pasted into health. */
    public function test_an_html_error_page_is_truncated_into_a_readable_message(): void
    {
        $base = config('integrations.cloudtalk.api_base');
        Http::fake(["{$base}/*" => Http::response('<html><head><title>Attention Required</title></head><body>'.str_repeat('Error 1010 ', 200).'</body></html>', 403)]);

        $integration = $this->integration();
        $run = app(SyncService::class)->run($integration);

        $this->assertLessThan(500, strlen($run->last_error));
        $this->assertStringNotContainsString('<html>', $run->last_error);
    }

    public function test_only_the_selected_datasets_are_written(): void
    {
        $this->fakeCloudTalk();

        $integration = $this->integration(['datasets' => ['Agents']]);
        app(SyncService::class)->run($integration);

        $this->assertNotEmpty($this->rows($integration, 'Agents'));
        $this->assertEmpty($this->rows($integration, 'Calls'));
        $this->assertEmpty($this->rows($integration, 'Campaigns'));
    }

    public function test_schema_lists_the_datasets_for_the_builders(): void
    {
        $this->fakeCloudTalk();
        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        $schema = $integration->provider()->schema($integration);

        $this->assertSame(CloudTalkProvider::DATASETS, array_keys($schema));
        $this->assertContains('Answer Rate', $schema['Agent Daily']);
        $this->assertContains('Date', $schema['Agent Daily']);
    }
}
