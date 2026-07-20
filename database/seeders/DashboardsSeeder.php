<?php

namespace Database\Seeders;

use App\Dashboard\Models\Chart;
use App\Dashboard\Models\Dashboard;
use App\Dashboard\Models\Metric;
use App\Integration\Models\Integration;
use App\Menu\Models\MenuItem;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Builds the two V1 dashboards the client asked for, on top of whatever
 * GhlContactsSeeder / MetaAdsSeeder just wrote:
 *
 *  - "GHL — Leads Dashboard": everything derivable from the Contacts dataset
 *    alone (source, SDR owner, stage, qualification, follow-ups).
 *  - "Meta Ads — Performance Dashboard": spend/lead/conversion performance
 *    from the Meta Marketing API datasets.
 *
 * Kept intentionally flat (KPI row + a handful of charts each) per the
 * client's "don't build a complex dashboard first" instruction. The full
 * Source → SDR Owner → Stage → Next Action → Qualification management view
 * they asked for is the existing generic "Raw Rows" table already built into
 * the dashboard UI — picking the Contacts dataset there surfaces exactly
 * that, with search, for free.
 */
class DashboardsSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'admin@vwn.local')->first() ?? User::first();
        $ghl = Integration::where('provider', 'gohighlevel')->first();
        $meta = Integration::where('provider', 'meta_ads')->first();

        if (! $user || ! $ghl || ! $meta) {
            $this->command?->warn('DashboardsSeeder skipped — run AdminUserSeeder, GhlContactsSeeder and MetaAdsSeeder first.');

            return;
        }

        $this->seedGhlDashboard($user, $ghl);
        $this->seedMetaDashboard($user, $meta);
        $this->removeEmptyLegacyMainDashboard();
    }

    /**
     * The 2026_07_17_000007 migration's own guard (`Integration::exists()`)
     * always fires on a fresh install, since it runs before this seeder — it
     * leaves behind an empty, is_default=true "Main Dashboard" that would
     * otherwise beat our GHL dashboard to the landing page. Safe to remove:
     * it's provably empty (no charts, no metrics) at this point in the seed.
     */
    private function removeEmptyLegacyMainDashboard(): void
    {
        $legacy = Dashboard::where('slug', 'main')->first();

        if ($legacy && $legacy->charts()->count() === 0 && $legacy->metrics()->count() === 0) {
            MenuItem::where('dashboard_id', $legacy->id)->delete();
            $legacy->delete();
        }
    }

    private function seedGhlDashboard(User $user, Integration $ghl): void
    {
        $dashboard = Dashboard::updateOrCreate(
            ['slug' => 'ghl-leads'],
            ['user_id' => $user->id, 'name' => 'GHL — Leads Dashboard', 'position' => 1, 'is_default' => true]
        );

        $dashboard->charts()->delete();
        $dashboard->metrics()->delete();

        $sheet = 'Contacts';

        $metrics = [
            ['title' => 'Total Leads', 'agg' => 'count', 'accent' => true, 'subtitle' => 'All synced contacts'],
            ['title' => 'Leads Contacted', 'agg' => 'count_if', 'filter' => ['Contacted Flag', 'eq', '1'], 'subtitle' => 'Past "New Lead" stage'],
            ['title' => 'Booked Calls', 'agg' => 'count_if', 'filter' => ['Booked Call Flag', 'eq', '1'], 'subtitle' => 'Stage = Booked Call'],
            ['title' => 'Qualified Calls', 'agg' => 'count_if', 'filter' => ['Qualified Call Flag', 'eq', '1'], 'subtitle' => 'Stage = Qualified Call Completed'],
            ['title' => 'Job Seekers', 'agg' => 'count_if', 'filter' => ['Qualification Status', 'eq', 'Job Seeker'], 'subtitle' => 'Meta inbound, wrong intent'],
            ['title' => 'Bad Fit Leads', 'agg' => 'count_if', 'filter' => ['Qualification Status', 'eq', 'Bad Fit'], 'subtitle' => 'Outside ICP'],
            ['title' => 'Not Qualified', 'agg' => 'count_if', 'filter' => ['Qualification Status', 'eq', 'Not Qualified'], 'subtitle' => 'Replied, not ready'],
            ['title' => 'Follow-ups Due', 'agg' => 'count_if', 'filter' => ['Follow-up Status', 'eq', 'Scheduled'], 'subtitle' => 'Next action scheduled'],
            ['title' => 'Overdue Follow-ups', 'agg' => 'count_if', 'filter' => ['Follow-up Status', 'eq', 'Overdue'], 'subtitle' => 'Next action date has passed'],
            ['title' => 'No Next Action', 'agg' => 'count_if', 'filter' => ['Follow-up Status', 'eq', 'No Next Action'], 'subtitle' => 'Leads stuck with no plan'],
        ];

        foreach ($metrics as $i => $m) {
            [$filterColumn, $filterOp, $filterValue] = $m['filter'] ?? [null, null, null];

            Metric::create([
                'user_id' => $user->id,
                'dashboard_id' => $dashboard->id,
                'integration_id' => $ghl->id,
                'title' => $m['title'],
                'subtitle' => $m['subtitle'] ?? null,
                'accent' => $m['accent'] ?? false,
                'mode' => 'simple',
                'sheet' => $sheet,
                'agg' => $m['agg'],
                'filter_column' => $filterColumn,
                'filter_operator' => $filterOp,
                'filter_value' => $filterValue,
                'format' => 'number',
                'decimals' => 0,
                'position' => $i,
            ]);
        }

        $charts = [
            ['title' => 'Leads by Source', 'type' => 'bar', 'label' => 'Source', 'agg' => 'count', 'col' => null],
            ['title' => 'Leads by SDR Owner', 'type' => 'bar', 'label' => 'Assigned User', 'agg' => 'count', 'col' => null],
            ['title' => 'Booked Calls by SDR', 'type' => 'bar', 'label' => 'Assigned User', 'agg' => 'sum', 'col' => 'Booked Call Flag'],
            ['title' => 'Qualification Breakdown', 'type' => 'doughnut', 'label' => 'Qualification Status', 'agg' => 'count', 'col' => null],
            ['title' => 'Current Stage — Where Leads Are Stuck', 'type' => 'horizontalBar', 'label' => 'Current Stage', 'agg' => 'count', 'col' => null],
            ['title' => 'Source → Booked Call Conversion %', 'type' => 'bar', 'label' => 'Source', 'agg' => 'avg', 'col' => 'Booked Call Rate'],
            ['title' => 'Source → Qualified Call Conversion %', 'type' => 'bar', 'label' => 'Source', 'agg' => 'avg', 'col' => 'Qualified Call Rate'],
        ];

        $this->createCharts($user, $dashboard, $ghl, $sheet, $charts);
    }

    private function seedMetaDashboard(User $user, Integration $meta): void
    {
        $dashboard = Dashboard::updateOrCreate(
            ['slug' => 'meta-ads-performance'],
            ['user_id' => $user->id, 'name' => 'Meta Ads — Performance Dashboard', 'position' => 2, 'is_default' => false]
        );

        $dashboard->charts()->delete();
        $dashboard->metrics()->delete();

        $perf = 'Daily Performance';
        $campaigns = 'Campaigns';

        $metrics = [
            ['title' => 'Total Spend', 'sheet' => $perf, 'agg' => 'sum', 'column' => 'Spend', 'format' => 'currency', 'accent' => true, 'subtitle' => 'Last 90 days'],
            ['title' => 'Total Leads', 'sheet' => $perf, 'agg' => 'sum', 'column' => 'Leads', 'subtitle' => 'Meta-reported leads'],
            ['title' => 'Total Clicks', 'sheet' => $perf, 'agg' => 'sum', 'column' => 'Clicks'],
            ['title' => 'Total Impressions', 'sheet' => $perf, 'agg' => 'sum', 'column' => 'Impressions'],
            ['title' => 'Avg. Cost Per Lead', 'sheet' => $perf, 'agg' => 'avg', 'column' => 'Cost Per Lead', 'format' => 'currency', 'decimals' => 2],
            ['title' => 'Avg. CTR', 'sheet' => $perf, 'agg' => 'avg', 'column' => 'CTR', 'format' => 'percent', 'decimals' => 2],
            ['title' => 'Booked Calls (Conversions)', 'sheet' => $perf, 'agg' => 'sum', 'column' => 'Conversions', 'decimals' => 0],
            ['title' => 'Blended ROAS', 'sheet' => $perf, 'agg' => 'avg', 'column' => 'ROAS', 'decimals' => 2],
            ['title' => 'Active Campaigns', 'sheet' => $campaigns, 'agg' => 'count_if', 'filter' => ['Status', 'eq', 'ACTIVE']],
        ];

        foreach ($metrics as $i => $m) {
            [$filterColumn, $filterOp, $filterValue] = $m['filter'] ?? [null, null, null];

            Metric::create([
                'user_id' => $user->id,
                'dashboard_id' => $dashboard->id,
                'integration_id' => $meta->id,
                'title' => $m['title'],
                'subtitle' => $m['subtitle'] ?? null,
                'accent' => $m['accent'] ?? false,
                'mode' => 'simple',
                'sheet' => $m['sheet'],
                'agg' => $m['agg'],
                'column' => $m['column'] ?? null,
                'filter_column' => $filterColumn,
                'filter_operator' => $filterOp,
                'filter_value' => $filterValue,
                'format' => $m['format'] ?? 'number',
                'decimals' => $m['decimals'] ?? 0,
                'position' => $i,
            ]);
        }

        Metric::create([
            'user_id' => $user->id,
            'dashboard_id' => $dashboard->id,
            'integration_id' => $meta->id,
            'title' => 'Cost Per Booked Call',
            'subtitle' => 'spend ÷ conversions',
            'mode' => 'formula',
            'expression' => '{spend} / {conversions}',
            'variables' => [
                'spend' => ['integration_id' => $meta->id, 'sheet' => $perf, 'agg' => 'sum', 'column' => 'Spend'],
                'conversions' => ['integration_id' => $meta->id, 'sheet' => $perf, 'agg' => 'sum', 'column' => 'Conversions'],
            ],
            'format' => 'currency',
            'decimals' => 2,
            'position' => count($metrics),
        ]);

        $charts = [
            ['title' => 'Spend by Campaign', 'type' => 'bar', 'label' => 'Campaign', 'agg' => 'sum', 'col' => 'Spend', 'sheet' => $perf],
            ['title' => 'Leads by Campaign', 'type' => 'bar', 'label' => 'Campaign', 'agg' => 'sum', 'col' => 'Leads', 'sheet' => $perf],
            ['title' => 'Booked Calls by Campaign', 'type' => 'bar', 'label' => 'Campaign', 'agg' => 'sum', 'col' => 'Conversions', 'sheet' => $perf],
            ['title' => 'Cost Per Lead by Campaign', 'type' => 'bar', 'label' => 'Campaign', 'agg' => 'avg', 'col' => 'Cost Per Lead', 'sheet' => $perf],
            ['title' => 'CTR by Campaign', 'type' => 'bar', 'label' => 'Campaign', 'agg' => 'avg', 'col' => 'CTR', 'sheet' => $perf],
            ['title' => 'Campaign Objective Split', 'type' => 'doughnut', 'label' => 'Objective', 'agg' => 'count', 'col' => null, 'sheet' => $campaigns],
        ];

        $this->createCharts($user, $dashboard, $meta, $perf, $charts);
    }

    private function createCharts(User $user, Dashboard $dashboard, Integration $integration, string $defaultSheet, array $charts): void
    {
        foreach ($charts as $i => $c) {
            $sheet = $c['sheet'] ?? $defaultSheet;

            Chart::create([
                'user_id' => $user->id,
                'dashboard_id' => $dashboard->id,
                'integration_id' => $integration->id,
                'title' => $c['title'],
                'type' => $c['type'],
                'sheet' => $sheet,
                'label_column' => $c['label'],
                'aggregate' => $c['agg'],
                'limit' => 12,
                'position' => $i,
                'is_system' => true,
                'series' => [[
                    'integration_id' => $integration->id,
                    'sheet' => $sheet,
                    'column' => $c['col'],
                    'agg' => $c['agg'],
                    'label' => $c['col'] ?? 'Count',
                ]],
            ]);
        }
    }
}
