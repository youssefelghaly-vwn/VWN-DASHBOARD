<?php

namespace Tests\Feature;

use App\Dashboard\Models\Chart;
use App\Dashboard\Models\Dashboard;
use App\Dashboard\Models\Metric;
use App\Dashboard\Services\DashboardData;
use App\DataHealth\Services\DataHealthService;
use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
use App\Integration\Providers\GoHighLevelProvider;
use App\Integration\Providers\IntegrationProvider;
use App\Integration\Providers\MetaAdsProvider;
use App\Integration\Services\IntegrationManager;
use App\Integration\Services\SyncContext;
use App\Integration\Services\SyncService;
use App\Metric\Services\MetricService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A stand-in provider proving that anything implementing IntegrationProvider
 * plugs into the generic sync / health / dashboard machinery with no other
 * changes.
 */
class FakeProvider implements IntegrationProvider
{
    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function connect(Integration $integration, array $credentials): void
    {
        $integration->fill(['name' => 'Fake', 'status' => 'connected'])->save();
    }

    public function disconnect(Integration $integration): void {}

    public function sync(Integration $integration, SyncContext $context): void
    {
        $context->write('Leads', [
            ['external_id' => '1', 'payload' => ['Source' => 'Meta', 'Value' => 100]],
            ['external_id' => '2', 'payload' => ['Source' => 'Meta', 'Value' => 50]],
            ['external_id' => '3', 'payload' => ['Source' => 'Google', 'Value' => 30]],
        ]);
    }

    public function schema(Integration $integration): array
    {
        return ['Leads' => ['Source', 'Value']];
    }
}

class IntegrationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.providers' => [
            'fake' => FakeProvider::class,
            'gohighlevel' => GoHighLevelProvider::class,
            'meta_ads' => MetaAdsProvider::class,
        ]]);

        $this->app->forgetInstance(IntegrationManager::class);
    }

    public function test_registry_resolves_every_provider(): void
    {
        $manager = app(IntegrationManager::class);

        $this->assertContains('fake', $manager->keys());
        $this->assertContains('meta_ads', $manager->keys());
        $this->assertSame('Fake', $manager->get('fake')->label());
    }

    public function test_sync_writes_local_records_and_a_sync_run(): void
    {
        $integration = Integration::create(['provider' => 'fake', 'name' => 'Fake', 'status' => 'connected']);

        $run = app(SyncService::class)->run($integration);

        $this->assertSame('success', $run->status);
        $this->assertSame(3, $run->records_synced);
        $this->assertSame(3, IntegrationRecord::where('integration_id', $integration->id)->count());
        $this->assertNotNull($integration->fresh()->last_synced_at);
    }

    public function test_data_health_report_is_generic(): void
    {
        $integration = Integration::create(['provider' => 'fake', 'name' => 'Fake', 'status' => 'connected']);
        app(SyncService::class)->run($integration);

        $report = app(DataHealthService::class)->report();

        $this->assertCount(1, $report);
        $this->assertSame('success', $report[0]['status']);
        $this->assertSame(3, $report[0]['records_synced']);
        $this->assertArrayHasKey('Leads', $report[0]['datasets']);
    }

    public function test_dashboard_chart_reads_only_local_rows(): void
    {
        $integration = Integration::create(['provider' => 'fake', 'name' => 'Fake', 'status' => 'connected']);
        app(SyncService::class)->run($integration);

        $user = User::factory()->create();
        $dashboard = Dashboard::create(['name' => 'Test']);
        $chart = Chart::create([
            'user_id' => $user->id, 'dashboard_id' => $dashboard->id, 'integration_id' => $integration->id,
            'title' => 'Leads by source', 'type' => 'bar', 'sheet' => 'Leads',
            'label_column' => 'Source', 'aggregate' => 'count', 'limit' => 10,
            'series' => [['integration_id' => $integration->id, 'sheet' => 'Leads', 'agg' => 'count', 'label' => 'Count']],
        ]);

        $built = app(DashboardData::class)->buildChart($chart);

        $this->assertSame(['Meta', 'Google'], $built['labels']);
        $this->assertSame([2, 1], $built['datasets'][0]['data']);
    }

    public function test_formula_metric_combines_filtered_counts(): void
    {
        $integration = Integration::create(['provider' => 'fake', 'name' => 'Fake', 'status' => 'connected']);
        app(SyncService::class)->run($integration);

        $metric = new Metric([
            'title' => 'Meta share', 'mode' => 'formula', 'integration_id' => $integration->id,
            'expression' => '{meta} / {all} * 100',
            'variables' => [
                'meta' => ['integration_id' => $integration->id, 'sheet' => 'Leads', 'agg' => 'count_if', 'filter_column' => 'Source', 'filter_operator' => 'eq', 'filter_value' => 'Meta'],
                'all' => ['integration_id' => $integration->id, 'sheet' => 'Leads', 'agg' => 'count'],
            ],
            'format' => 'percent', 'decimals' => 1,
        ]);

        $this->assertSame('66.7%', app(MetricService::class)->build($metric)['display']);
    }
}
