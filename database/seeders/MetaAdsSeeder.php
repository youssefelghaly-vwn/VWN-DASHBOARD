<?php

namespace Database\Seeders;

use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
use App\Integration\Models\SyncRun;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds one 'meta_ads' Integration with all five datasets the real
 * MetaAdsProvider produces (Ad Accounts, Campaigns, Ad Sets, Ads, Daily
 * Performance), shaped exactly like MetaAdsProvider's fetch* methods would
 * from a live Graph API sync. Campaign names match GhlContactsSeeder's
 * "Campaign / Ad Source" values so the two dashboards tell a consistent story
 * even though (by design) each integration's rows are read independently.
 *
 * Reuses an already-connected 'meta_ads' Integration row if one exists
 * (matched by provider only — never by name, since the real one may be named
 * differently than this file's fallback) rather than creating a duplicate.
 * Existing credentials/config/name/status are left untouched either way.
 *
 * WARNING — destructive: whatever is currently in that integration's five
 * datasets (including real synced rows, if any) is deleted and replaced with
 * this seeded demo set every time this runs. Don't run against a database
 * whose Meta Ads data you need to keep.
 */
class MetaAdsSeeder extends Seeder
{
    private const CAMPAIGNS = [
        ...GhlContactsSeeder::META_LF_CAMPAIGNS,
        ...GhlContactsSeeder::META_BC_CAMPAIGNS,
    ];

    public function run(): void
    {
        $integration = Integration::where('provider', 'meta_ads')->first();

        if (! $integration) {
            $integration = Integration::create([
                'provider' => 'meta_ads',
                'name' => 'Meta Ads — VWN Growth',
                'credentials' => ['access_token' => null, 'ad_account_id' => 'act_1234567890'],
                'config' => ['account_name' => 'VWN Growth', 'currency' => 'USD'],
                'status' => 'connected',
            ]);
        }

        $integration->forceFill(['last_synced_at' => now()])->save();

        foreach (['Ad Accounts', 'Campaigns', 'Ad Sets', 'Ads', 'Daily Performance'] as $dataset) {
            $integration->records()->where('dataset', $dataset)->delete();
        }

        $now = now();

        $adAccountCount = $this->writeAdAccount($integration, $now);
        $campaignIds = $this->writeCampaigns($integration, $now);
        $adSetIds = $this->writeAdSets($integration, $now, $campaignIds);
        $adsCount = $this->writeAds($integration, $now, $adSetIds);
        $insightsCount = $this->writeInsights($integration, $now);

        $totalRecords = $adAccountCount + count($campaignIds) + count($adSetIds) + $adsCount + $insightsCount;

        SyncRun::create([
            'integration_id' => $integration->id,
            'status' => SyncRun::SUCCESS,
            'started_at' => $now->copy()->subSeconds(6),
            'finished_at' => $now,
            'records_synced' => $totalRecords,
            'failed_records' => 0,
            'meta' => ['datasets' => [
                'Ad Accounts' => ['ok' => true, 'count' => $adAccountCount, 'ms' => 220, 'error' => null],
                'Campaigns' => ['ok' => true, 'count' => count($campaignIds), 'ms' => 340, 'error' => null],
                'Ad Sets' => ['ok' => true, 'count' => count($adSetIds), 'ms' => 410, 'error' => null],
                'Ads' => ['ok' => true, 'count' => $adsCount, 'ms' => 560, 'error' => null],
                'Daily Performance' => ['ok' => true, 'count' => $insightsCount, 'ms' => 2100, 'error' => null],
            ]],
        ]);

        $this->command?->info('Seeded '.$totalRecords.' Meta Ads records on integration #'.$integration->id);
    }

    private function insert(Integration $integration, string $dataset, array $rows): void
    {
        $now = now();
        $data = array_map(fn (array $r) => [
            'integration_id' => $integration->id,
            'dataset' => $dataset,
            'external_id' => $r['external_id'] ?? null,
            'payload' => json_encode($r['payload']),
            'record_date' => $r['record_date'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        foreach (array_chunk($data, 200) as $chunk) {
            IntegrationRecord::insert($chunk);
        }
    }

    private function writeAdAccount(Integration $integration, Carbon $now): int
    {
        $this->insert($integration, 'Ad Accounts', [[
            'external_id' => 'act_1234567890',
            'payload' => [
                'Account' => 'VWN Growth',
                'Status' => 'Active',
                'Currency' => 'USD',
                'Amount Spent' => 48250.75,
            ],
        ]]);

        return 1;
    }

    /** @return array<string,string> campaign name => external id */
    private function writeCampaigns(Integration $integration, Carbon $now): array
    {
        $ids = [];
        $rows = [];

        foreach (self::CAMPAIGNS as $i => $name) {
            $id = '2380000000'.(100 + $i);
            $ids[$name] = $id;
            $isLf = str_starts_with($name, 'Meta LF');

            $rows[] = [
                'external_id' => $id,
                'payload' => [
                    'Campaign' => $name,
                    'Objective' => $isLf ? 'OUTCOME_LEADS' : 'OUTCOME_ENGAGEMENT',
                    'Status' => $name === 'Meta LF — Remote Staffing Awareness' ? 'PAUSED' : 'ACTIVE',
                    'Daily Budget' => $isLf ? 120.00 : 90.00,
                    'Lifetime Budget' => 0,
                ],
            ];
        }

        $this->insert($integration, 'Campaigns', $rows);

        return $ids;
    }

    /** @return array<int,array{name:string,campaign:string,id:string}> */
    private function writeAdSets(Integration $integration, Carbon $now, array $campaignIds): array
    {
        $goals = ['LEAD_GENERATION', 'QUALITY_CALL', 'LEAD_GENERATION'];
        $adSets = [];
        $rows = [];
        $i = 0;

        foreach ($campaignIds as $campaign => $campaignId) {
            $count = random_int(1, 2);

            for ($n = 0; $n < $count; $n++) {
                $id = '2390000000'.(100 + $i);
                $name = $campaign.' — Ad Set '.($n + 1);
                $adSets[] = ['name' => $name, 'campaign' => $campaign, 'id' => $id];

                $rows[] = [
                    'external_id' => $id,
                    'payload' => [
                        'Ad Set' => $name,
                        'Campaign' => $campaign,
                        'Status' => 'ACTIVE',
                        'Daily Budget' => round(random_int(30, 80), 2),
                        'Optimization Goal' => $goals[$i % count($goals)],
                    ],
                ];
                $i++;
            }
        }

        $this->insert($integration, 'Ad Sets', $rows);

        return $adSets;
    }

    private function writeAds(Integration $integration, Carbon $now, array $adSets): int
    {
        $creatives = ['Static — Testimonial', 'Video — Meet the Team', 'Carousel — Services', 'Static — Offer'];
        $rows = [];
        $i = 0;

        foreach ($adSets as $set) {
            $count = random_int(1, 2);

            for ($n = 0; $n < $count; $n++) {
                $id = '2400000000'.(100 + $i);
                $rows[] = [
                    'external_id' => $id,
                    'payload' => [
                        'Ad' => $set['name'].' — '.$creatives[$i % count($creatives)],
                        'Ad Set' => $set['name'],
                        'Campaign' => $set['campaign'],
                        'Status' => 'ACTIVE',
                        'Effective Status' => 'ACTIVE',
                    ],
                ];
                $i++;
            }
        }

        $this->insert($integration, 'Ads', $rows);

        return count($rows);
    }

    private function writeInsights(Integration $integration, Carbon $now): int
    {
        $rows = [];
        $days = 90;

        // Per-campaign baseline daily spend / cost-per-lead, LF vs BC skew.
        $baselines = [];
        foreach (self::CAMPAIGNS as $name) {
            $isLf = str_starts_with($name, 'Meta LF');
            $baselines[$name] = [
                'spend' => $isLf ? random_int(60, 140) : random_int(40, 100),
                'cpl' => $isLf ? random_int(9, 18) : random_int(14, 28),
            ];
        }

        for ($d = $days - 1; $d >= 0; $d--) {
            $date = $now->copy()->subDays($d);
            $weekday = (int) $date->format('N');
            $weekendFactor = $weekday >= 6 ? 0.65 : 1.0;

            foreach (self::CAMPAIGNS as $name) {
                if ($name === 'Meta LF — Remote Staffing Awareness') {
                    continue; // paused campaign — no recent spend
                }

                $base = $baselines[$name];
                $spend = round($base['spend'] * $weekendFactor * (0.8 + (mt_rand(0, 40) / 100)), 2);
                $cpl = max(4, $base['cpl'] * (0.85 + (mt_rand(0, 30) / 100)));
                $leads = $spend > 0 ? round($spend / $cpl, 2) : 0;
                $clicks = round($spend / (mt_rand(60, 140) / 100));
                $impressions = round($clicks * mt_rand(35, 65));
                $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0;
                $cpc = $clicks > 0 ? round($spend / $clicks, 2) : 0;
                $cpm = $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : 0;
                $conversions = round($leads * (mt_rand(20, 45) / 100), 2); // booked calls
                $roas = round(mt_rand(150, 420) / 100, 2);

                $rows[] = [
                    'external_id' => null,
                    'record_date' => $date->toDateString(),
                    'payload' => [
                        'Date' => $date->toDateString(),
                        'Campaign' => $name,
                        'Spend' => $spend,
                        'Clicks' => $clicks,
                        'Impressions' => $impressions,
                        'CTR' => $ctr,
                        'CPC' => $cpc,
                        'CPM' => $cpm,
                        'Leads' => $leads,
                        'Conversions' => $conversions,
                        'Cost Per Lead' => round($cpl, 2),
                        'ROAS' => $roas,
                    ],
                ];
            }
        }

        $this->insert($integration, 'Daily Performance', $rows);

        return count($rows);
    }
}
