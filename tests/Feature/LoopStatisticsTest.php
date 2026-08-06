<?php

namespace Tests\Feature;

use App\Dashboard\Models\Dashboard;
use App\Dashboard\Models\LoopStatistic;
use App\Dashboard\Models\Metric;
use App\Dashboard\Models\Section;
use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
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
}
