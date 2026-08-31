<?php

namespace Tests\Feature;

use App\Dashboard\Models\Dashboard;
use App\Dashboard\Models\LoopStatistic;
use App\Dashboard\Models\Metric;
use App\Dashboard\Models\Section;
use App\Dashboard\Services\LoopExpander;
use App\Integration\Jobs\SyncIntegrationJob;
use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
use App\Integration\Providers\IntegrationProvider;
use App\Integration\Services\SyncContext;
use App\Integration\Services\SyncService;
use App\Metric\Services\MetricService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A loop fans template widgets out across a column's distinct values: one
 * sub-section per value, each holding copies scoped to that value via an
 * injected "{column} = value" condition.
 */
class LoopStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private Integration $integration;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->integration = Integration::create([
            'provider' => 'gohighlevel', 'name' => 'GHL', 'status' => 'connected',
        ]);

        $rows = [
            ['Owner' => 'Kareem Barkat', 'Outreach Stages' => '1st SMS, 1st Email'],
            ['Owner' => 'Kareem Barkat', 'Outreach Stages' => '1st Email'],
            ['Owner' => 'Ahmed Ali', 'Outreach Stages' => '1st SMS'],
            ['Owner' => 'Zainab Makarfi', 'Outreach Stages' => '1st Call'],
        ];

        foreach ($rows as $i => $r) {
            IntegrationRecord::create([
                'integration_id' => $this->integration->id, 'dataset' => 'Opportunities',
                'external_id' => (string) $i, 'payload' => $r,
            ]);
        }
    }

    /**
     * The SDR roster, as the Users dataset carries it. Note Ahmed owns
     * opportunities but isn't an SDR, and Hana is an SDR who owns none — the two
     * cases that separate "loop over the roster" from "loop over the data".
     */
    private function seedUsers(): void
    {
        $users = [
            ['Name' => 'Kareem Barkat-SDR', 'Email' => 'kareem@example.com', 'Role' => 'user'],
            ['Name' => 'Zainab Makarfi-SDR', 'Email' => 'zainab@example.com', 'Role' => 'user'],
            ['Name' => 'Hana Fouad-SDR', 'Email' => 'hana@example.com', 'Role' => 'user'],
            ['Name' => 'Ahmed Ali', 'Email' => 'ahmed@example.com', 'Role' => 'admin'],
        ];

        foreach ($users as $i => $u) {
            IntegrationRecord::create([
                'integration_id' => $this->integration->id, 'dataset' => 'Users',
                'external_id' => 'u'.$i, 'payload' => $u,
            ]);
        }
    }

    private function smsTemplate(): array
    {
        return [
            'title' => 'SMS Sent', 'mode' => 'simple',
            'integration_id' => $this->integration->id, 'sheet' => 'Opportunities',
            'agg' => 'count_if', 'format' => 'number', 'decimals' => 0,
            'filters' => [['column' => 'Outreach Stages', 'operator' => 'has_any', 'value' => '1st SMS']],
        ];
    }

    private function storeLoop(User $admin, Dashboard $dash, array $overrides = [])
    {
        return $this->actingAs($admin)->postJson("/dashboards/{$dash->id}/loops", array_merge([
            'name' => 'SDR — SMS', 'integration_id' => $this->integration->id,
            'dataset' => 'Opportunities', 'column' => 'Owner',
            'metrics' => [$this->smsTemplate()], 'charts' => [],
        ], $overrides));
    }

    private function metricValueFor(string $ownerSection): float
    {
        $section = Section::where('title', $ownerSection)->firstOrFail();
        $metric = Metric::where('section_id', $section->id)->firstOrFail();

        return app(MetricService::class)->build($metric)['value'];
    }

    public function test_it_creates_a_subsection_and_scoped_metric_per_owner(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::create(['name' => 'Exec']);

        $this->storeLoop($admin, $dash)->assertCreated()->assertJsonPath('result.values', ['Ahmed Ali', 'Kareem Barkat', 'Zainab Makarfi']);

        // 1 parent section + 3 owner sub-sections.
        $this->assertSame(4, Section::where('dashboard_id', $dash->id)->count());
        $this->assertSame(3, Metric::whereNotNull('loop_id')->count());

        // Each metric is scoped to its owner: Kareem has 1 SMS row, Ahmed 1, Zainab 0.
        $this->assertSame(1.0, $this->metricValueFor('Kareem Barkat'));
        $this->assertSame(1.0, $this->metricValueFor('Ahmed Ali'));
        $this->assertSame(0.0, $this->metricValueFor('Zainab Makarfi'));
    }

    public function test_value_filter_narrows_which_owners_loop(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::create(['name' => 'Exec']);

        $this->storeLoop($admin, $dash, ['value_operator' => 'contains', 'value_match' => 'kareem'])
            ->assertCreated()
            ->assertJsonPath('result.values', ['Kareem Barkat']);

        $this->assertSame(1, Metric::whereNotNull('loop_id')->count());
    }

    public function test_refresh_picks_up_a_new_owner(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::create(['name' => 'Exec']);
        $this->storeLoop($admin, $dash)->assertCreated();

        IntegrationRecord::create([
            'integration_id' => $this->integration->id, 'dataset' => 'Opportunities',
            'external_id' => 'new', 'payload' => ['Owner' => 'Sara Nabil', 'Outreach Stages' => '1st SMS'],
        ]);

        // RecordReader is request-scoped (fresh per HTTP request in production);
        // flush it so the refresh request re-reads rows and sees the new record,
        // matching real cross-request behaviour.
        $this->app->forgetScopedInstances();

        $loop = LoopStatistic::first();
        $this->actingAs($admin)->postJson("/loops/{$loop->id}/refresh")
            ->assertOk()->assertJsonPath('result.values', ['Ahmed Ali', 'Kareem Barkat', 'Sara Nabil', 'Zainab Makarfi']);

        $this->assertSame(4, Metric::whereNotNull('loop_id')->count());
        $this->assertSame(1.0, $this->metricValueFor('Sara Nabil'));
    }

    public function test_delete_purges_generated_sections_and_widgets(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::create(['name' => 'Exec']);
        $this->storeLoop($admin, $dash)->assertCreated();

        $loop = LoopStatistic::first();
        $this->actingAs($admin)->deleteJson("/loops/{$loop->id}")->assertOk();

        $this->assertSame(0, Metric::whereNotNull('loop_id')->count());
        $this->assertSame(0, Section::where('dashboard_id', $dash->id)->count());
        $this->assertDatabaseMissing('loop_statistics', ['id' => $loop->id]);
    }

    public function test_editing_a_loop_reapplies_new_templates_to_every_value(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::create(['name' => 'Exec']);
        $this->storeLoop($admin, $dash)->assertCreated();

        // 3 owners × 1 metric template = 3 generated metrics.
        $this->assertSame(3, Metric::whereNotNull('loop_id')->count());

        $loop = LoopStatistic::first();

        // Edit: add a second metric template and rename the loop.
        $this->app->forgetScopedInstances();
        $this->actingAs($admin)->putJson("/loops/{$loop->id}", [
            'name' => 'SDR — Outreach', 'integration_id' => $this->integration->id,
            'dataset' => 'Opportunities', 'column' => 'Owner',
            'metrics' => [
                $this->smsTemplate(),
                ['title' => 'Total', 'mode' => 'simple', 'integration_id' => $this->integration->id, 'sheet' => 'Opportunities', 'agg' => 'count', 'format' => 'number', 'decimals' => 0, 'filters' => []],
            ],
            'charts' => [],
        ])->assertOk();

        // 3 owners × 2 templates = 6 metrics now; parent section renamed.
        $this->assertSame(6, Metric::whereNotNull('loop_id')->count());
        $this->assertSame('SDR — Outreach', $loop->fresh()->name);
        $this->assertDatabaseHas('dashboard_sections', ['id' => $loop->section_id, 'title' => 'SDR — Outreach']);
    }

    public function test_formula_template_is_scoped_per_owner_in_every_variable(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::create(['name' => 'Exec']);

        $formula = [
            'title' => 'SMS Rate', 'mode' => 'formula', 'format' => 'percent', 'decimals' => 0,
            'integration_id' => $this->integration->id,
            'expression' => '{sms} / {all} * 100',
            'variables' => [
                'sms' => ['integration_id' => $this->integration->id, 'sheet' => 'Opportunities', 'agg' => 'count_if', 'filters' => [['column' => 'Outreach Stages', 'operator' => 'has_any', 'value' => '1st SMS']]],
                'all' => ['integration_id' => $this->integration->id, 'sheet' => 'Opportunities', 'agg' => 'count'],
            ],
        ];

        $this->storeLoop($admin, $dash, ['metrics' => [$formula]])->assertCreated();

        // Kareem: 1 of his 2 opportunities has an SMS → 50%.
        $this->assertSame(50.0, $this->metricValueFor('Kareem Barkat'));
    }

    public function test_it_loops_over_a_roster_and_scopes_widgets_by_another_column(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::create(['name' => 'Exec']);
        $this->seedUsers();

        // Opportunities carry "Kareem Barkat" / "Zainab Makarfi" as Owner, while
        // the roster names them "…-SDR". Match on the roster name so the sections
        // are named after the person, and scope with a "contains"-shaped value.
        IntegrationRecord::create([
            'integration_id' => $this->integration->id, 'dataset' => 'Opportunities',
            'external_id' => 'x1', 'payload' => ['Owner' => 'Kareem Barkat-SDR', 'Outreach Stages' => '1st SMS'],
        ]);

        $this->storeLoop($admin, $dash, [
            'dataset' => 'Users',
            'column' => 'Name',
            'scope_column' => 'Owner',
            'value_operator' => 'contains',
            'value_match' => 'SDR',
        ])->assertCreated()->assertJsonPath('result.values', [
            'Hana Fouad-SDR', 'Kareem Barkat-SDR', 'Zainab Makarfi-SDR',
        ]);

        // Every SDR on the roster gets a sub-section — including Hana, who owns
        // no opportunities at all. Ahmed owns rows but isn't an SDR, so he's out.
        $this->assertSame(4, Section::where('dashboard_id', $dash->id)->count());
        $this->assertSame(3, Metric::whereNotNull('loop_id')->count());

        // The generated metrics filter Opportunities on Owner, not on Name. The
        // loop's condition is appended after the template's own filters.
        $metric = Metric::whereNotNull('loop_id')->first();
        $filters = $metric->filters;
        $title = Section::findOrFail($metric->section_id)->title;
        $this->assertSame(
            ['column' => 'Owner', 'operator' => 'eq', 'value' => $title],
            end($filters),
        );

        $this->assertSame(1.0, $this->metricValueFor('Kareem Barkat-SDR'));
        $this->assertSame(0.0, $this->metricValueFor('Hana Fouad-SDR'));
    }

    public function test_scope_column_defaults_to_the_loop_column(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::create(['name' => 'Exec']);

        $this->storeLoop($admin, $dash)->assertCreated();

        $metric = Metric::whereNotNull('loop_id')->first();
        $filters = $metric->filters;
        $this->assertSame('Owner', end($filters)['column']);
        $this->assertNull(LoopStatistic::first()->scope_column);
    }

    public function test_sync_values_rebuilds_only_when_the_value_set_changed(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::create(['name' => 'Exec', 'user_id' => $admin->id]);
        $this->storeLoop($admin, $dash)->assertCreated();

        $loop = LoopStatistic::first();
        $expander = app(LoopExpander::class);

        // Nothing changed upstream — no rebuild, so the widget rows keep their ids.
        $idsBefore = Metric::whereNotNull('loop_id')->pluck('id')->all();
        $this->assertFalse($expander->syncValues($loop->fresh(), $admin->id));
        $this->assertSame($idsBefore, Metric::whereNotNull('loop_id')->pluck('id')->all());

        // A new owner appears: rebuild, and the new value is materialised.
        IntegrationRecord::create([
            'integration_id' => $this->integration->id, 'dataset' => 'Opportunities',
            'external_id' => 'new', 'payload' => ['Owner' => 'Sara Nabil', 'Outreach Stages' => '1st SMS'],
        ]);
        $this->app->forgetScopedInstances();

        $this->assertTrue(app(LoopExpander::class)->syncValues($loop->fresh(), $admin->id));
        $this->assertSame(4, Metric::whereNotNull('loop_id')->count());
        $this->assertSame(1.0, $this->metricValueFor('Sara Nabil'));
    }

    public function test_a_sync_picks_up_a_new_value_without_a_manual_refresh(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::create(['name' => 'Exec', 'user_id' => $admin->id]);
        $this->storeLoop($admin, $dash)->assertCreated();

        $this->assertSame(3, Metric::whereNotNull('loop_id')->count());

        // A provider whose sync writes one extra owner into Opportunities.
        config()->set('integrations.providers.fake', FakeRosterProvider::class);
        $this->integration->update(['provider' => 'fake']);
        $this->app->forgetScopedInstances();

        (new SyncIntegrationJob($this->integration->id))->handle(
            app(SyncService::class),
            app(LoopExpander::class),
        );

        // The loop re-expanded on its own: Sara now has a sub-section + metric.
        $this->assertSame(4, Metric::whereNotNull('loop_id')->count());
        $this->assertDatabaseHas('dashboard_sections', ['title' => 'Sara Nabil']);
    }

    public function test_a_sync_leaves_loops_alone_when_the_values_are_unchanged(): void
    {
        $admin = $this->admin();
        $dash = Dashboard::create(['name' => 'Exec', 'user_id' => $admin->id]);
        $this->storeLoop($admin, $dash)->assertCreated();

        $idsBefore = Metric::whereNotNull('loop_id')->pluck('id')->all();

        // Same rows back from the provider — the loop must not be rebuilt, or
        // every 5-minute sync would churn widget ids and drop manual ordering.
        config()->set('integrations.providers.fake', FakeStaticProvider::class);
        $this->integration->update(['provider' => 'fake']);
        $this->app->forgetScopedInstances();

        (new SyncIntegrationJob($this->integration->id))->handle(
            app(SyncService::class),
            app(LoopExpander::class),
        );

        $this->assertSame($idsBefore, Metric::whereNotNull('loop_id')->pluck('id')->all());
    }
}

/** Writes the original owners plus one new one, so the value set grows. */
class FakeRosterProvider implements IntegrationProvider
{
    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function connect(Integration $integration, array $credentials): void {}

    public function disconnect(Integration $integration): void {}

    public function sync(Integration $integration, SyncContext $context): void
    {
        $context->write('Opportunities', array_map(fn (array $p) => ['payload' => $p], [
            ['Owner' => 'Kareem Barkat', 'Outreach Stages' => '1st SMS, 1st Email'],
            ['Owner' => 'Ahmed Ali', 'Outreach Stages' => '1st SMS'],
            ['Owner' => 'Zainab Makarfi', 'Outreach Stages' => '1st Call'],
            ['Owner' => 'Sara Nabil', 'Outreach Stages' => '1st SMS'],
        ]));
    }

    public function schema(Integration $integration): array
    {
        return ['Opportunities' => ['Owner', 'Outreach Stages']];
    }
}

/** Writes back exactly the owners that were already there. */
class FakeStaticProvider extends FakeRosterProvider
{
    public function sync(Integration $integration, SyncContext $context): void
    {
        $context->write('Opportunities', array_map(fn (array $p) => ['payload' => $p], [
            ['Owner' => 'Kareem Barkat', 'Outreach Stages' => '1st SMS, 1st Email'],
            ['Owner' => 'Ahmed Ali', 'Outreach Stages' => '1st SMS'],
            ['Owner' => 'Zainab Makarfi', 'Outreach Stages' => '1st Call'],
        ]));
    }
}
