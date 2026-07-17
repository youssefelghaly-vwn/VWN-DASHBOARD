<?php

namespace App\Integration\Providers;

use App\Integration\Models\Integration;
use App\Integration\Providers\Meta\MetaClient;
use App\Integration\Services\SyncContext;
use App\Support\CastsValues;
use RuntimeException;

/**
 * Meta Ads integration. Follows exactly the same structure as every other
 * provider — connect / disconnect / sync / schema — and writes five datasets:
 * Ad Accounts, Campaigns, Ad Sets, Ads, and Daily Performance (spend, clicks,
 * impressions, CTR, CPC, CPM, leads, conversions, cost per lead, ROAS).
 */
class MetaAdsProvider implements IntegrationProvider
{
    use CastsValues;

    public const DATASETS = ['Ad Accounts', 'Campaigns', 'Ad Sets', 'Ads', 'Daily Performance'];

    public function __construct(private MetaClient $client) {}

    public function key(): string
    {
        return 'meta_ads';
    }

    public function label(): string
    {
        return 'Meta Ads';
    }

    public function connect(Integration $integration, array $credentials): void
    {
        $token = trim($credentials['access_token'] ?? '');
        $account = trim($credentials['ad_account_id'] ?? '');

        if ($token === '' || $account === '') {
            throw new RuntimeException('A Meta access token and ad account id are required.');
        }

        // Normalize to the act_ prefix the Graph API expects.
        $account = str_starts_with($account, 'act_') ? $account : 'act_'.$account;

        $integration->forceFill([
            'provider' => $this->key(),
            'credentials' => ['access_token' => $token, 'ad_account_id' => $account],
        ]);

        $body = $this->client->get($integration, "/{$account}", ['fields' => 'name,account_status,currency']);
        $name = $body['name'] ?? $account;

        $integration->fill([
            'name' => "Meta Ads — {$name}",
            'config' => array_merge($integration->config ?? [], [
                'account_name' => $name,
                'currency' => $body['currency'] ?? null,
                'datasets' => $integration->setting('datasets', self::DATASETS),
            ]),
            'status' => 'connected',
        ]);

        $integration->save();
    }

    public function disconnect(Integration $integration): void
    {
        // Token revocation is handled in Meta's own settings; nothing to do.
    }

    public function sync(Integration $integration, SyncContext $context): void
    {
        $account = $integration->credential('ad_account_id');

        $this->guard($context, 'Ad Accounts', fn () => $context->write('Ad Accounts', $this->fetchAccount($integration, $account)));
        $this->guard($context, 'Campaigns', fn () => $context->write('Campaigns', $this->fetchCampaigns($integration, $account)));
        $this->guard($context, 'Ad Sets', fn () => $context->write('Ad Sets', $this->fetchAdSets($integration, $account)));
        $this->guard($context, 'Ads', fn () => $context->write('Ads', $this->fetchAds($integration, $account)));
        $this->guard($context, 'Daily Performance', fn () => $context->write('Daily Performance', $this->fetchInsights($integration, $account)));
    }

    public function schema(Integration $integration): array
    {
        $out = [];

        foreach (self::DATASETS as $dataset) {
            $rows = $integration->rows($dataset);

            $cols = [];
            foreach ($rows as $row) {
                foreach (array_keys($row) as $k) {
                    $cols[$k] = true;
                }
            }

            $out[$dataset] = array_keys($cols) ?: $this->baseColumns($dataset);
        }

        return $out;
    }

    private function baseColumns(string $dataset): array
    {
        return match ($dataset) {
            'Ad Accounts' => ['Account', 'Status', 'Currency', 'Amount Spent'],
            'Campaigns' => ['Campaign', 'Objective', 'Status', 'Daily Budget'],
            'Ad Sets' => ['Ad Set', 'Campaign', 'Status', 'Daily Budget'],
            'Ads' => ['Ad', 'Ad Set', 'Campaign', 'Status'],
            'Daily Performance' => ['Date', 'Campaign', 'Spend', 'Clicks', 'Impressions', 'CTR', 'CPC', 'CPM', 'Leads', 'Conversions', 'Cost Per Lead', 'ROAS'],
            default => [],
        };
    }

    /* ===================== fetchers ===================== */

    private function guard(SyncContext $context, string $dataset, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $context->fail($dataset, $e->getMessage());
        }
    }

    private function record(?string $id, array $payload, ?string $date = null): array
    {
        return ['external_id' => $id, 'payload' => $payload, 'record_date' => $date];
    }

    private function fetchAccount(Integration $i, string $account): array
    {
        $body = $this->client->get($i, "/{$account}", [
            'fields' => 'name,account_status,currency,amount_spent',
        ]);

        return [$this->record($body['id'] ?? $account, [
            'Account' => $this->str($body['name'] ?? $account),
            'Status' => $this->accountStatus($body['account_status'] ?? null),
            'Currency' => $this->str($body['currency'] ?? ''),
            'Amount Spent' => $this->num(($body['amount_spent'] ?? 0) / 100),
        ])];
    }

    private function fetchCampaigns(Integration $i, string $account): array
    {
        $rows = $this->client->paginate($i, "/{$account}/campaigns", [
            'fields' => 'name,objective,status,daily_budget,lifetime_budget',
        ]);

        return array_map(fn ($c) => $this->record($c['id'] ?? null, [
            'Campaign' => $this->str($c['name'] ?? ''),
            'Objective' => $this->str($c['objective'] ?? ''),
            'Status' => $this->str($c['status'] ?? ''),
            'Daily Budget' => $this->num(($c['daily_budget'] ?? 0) / 100),
            'Lifetime Budget' => $this->num(($c['lifetime_budget'] ?? 0) / 100),
        ]), $rows);
    }

    private function fetchAdSets(Integration $i, string $account): array
    {
        $rows = $this->client->paginate($i, "/{$account}/adsets", [
            'fields' => 'name,campaign{name},status,daily_budget,optimization_goal',
        ]);

        return array_map(fn ($s) => $this->record($s['id'] ?? null, [
            'Ad Set' => $this->str($s['name'] ?? ''),
            'Campaign' => $this->str($s['campaign']['name'] ?? ''),
            'Status' => $this->str($s['status'] ?? ''),
            'Daily Budget' => $this->num(($s['daily_budget'] ?? 0) / 100),
            'Optimization Goal' => $this->str($s['optimization_goal'] ?? ''),
        ]), $rows);
    }

    private function fetchAds(Integration $i, string $account): array
    {
        $rows = $this->client->paginate($i, "/{$account}/ads", [
            'fields' => 'name,adset{name},campaign{name},status,effective_status',
        ]);

        return array_map(fn ($a) => $this->record($a['id'] ?? null, [
            'Ad' => $this->str($a['name'] ?? ''),
            'Ad Set' => $this->str($a['adset']['name'] ?? ''),
            'Campaign' => $this->str($a['campaign']['name'] ?? ''),
            'Status' => $this->str($a['status'] ?? ''),
            'Effective Status' => $this->str($a['effective_status'] ?? ''),
        ]), $rows);
    }

    /**
     * Daily performance insights at campaign level, one row per campaign per day.
     * Derived metrics (leads, conversions, cost per lead, ROAS) are pulled out of
     * Meta's `actions` / `cost_per_action_type` / `purchase_roas` breakdowns.
     */
    private function fetchInsights(Integration $i, string $account): array
    {
        $daysBack = (int) config('integrations.meta_ads.insights_days_back', 90);

        $rows = $this->client->paginate($i, "/{$account}/insights", [
            'level' => 'campaign',
            'time_increment' => 1,
            'date_preset' => $this->datePreset($daysBack),
            'fields' => 'campaign_name,date_start,spend,clicks,impressions,ctr,cpc,cpm,actions,cost_per_action_type,purchase_roas',
        ]);

        return array_map(function ($r) {
            $leads = $this->actionValue($r['actions'] ?? [], ['lead', 'onsite_conversion.lead_grouped']);
            $conversions = $this->actionValue($r['actions'] ?? [], ['offsite_conversion.fb_pixel_purchase', 'purchase']);
            $costPerLead = $this->actionValue($r['cost_per_action_type'] ?? [], ['lead', 'onsite_conversion.lead_grouped']);
            $roas = $this->firstValue($r['purchase_roas'] ?? []);
            $date = $r['date_start'] ?? null;

            return $this->record(null, [
                'Date' => $this->str($date),
                'Campaign' => $this->str($r['campaign_name'] ?? ''),
                'Spend' => $this->num($r['spend'] ?? 0),
                'Clicks' => $this->num($r['clicks'] ?? 0),
                'Impressions' => $this->num($r['impressions'] ?? 0),
                'CTR' => $this->num($r['ctr'] ?? 0),
                'CPC' => $this->num($r['cpc'] ?? 0),
                'CPM' => $this->num($r['cpm'] ?? 0),
                'Leads' => $this->num($leads),
                'Conversions' => $this->num($conversions),
                'Cost Per Lead' => $this->num($costPerLead),
                'ROAS' => $this->num($roas),
            ], $date);
        }, $rows);
    }

    /** Sum the `value` of the first matching action type(s) in a breakdown array. */
    private function actionValue(array $actions, array $types): float
    {
        foreach ($actions as $action) {
            if (in_array($action['action_type'] ?? '', $types, true)) {
                return (float) ($action['value'] ?? 0);
            }
        }

        return 0.0;
    }

    private function firstValue(array $entries): float
    {
        foreach ($entries as $entry) {
            return (float) ($entry['value'] ?? 0);
        }

        return 0.0;
    }

    private function datePreset(int $daysBack): string
    {
        return match (true) {
            $daysBack <= 7 => 'last_7d',
            $daysBack <= 14 => 'last_14d',
            $daysBack <= 30 => 'last_30d',
            $daysBack <= 90 => 'last_90d',
            default => 'last_90d',
        };
    }

    private function accountStatus(mixed $code): string
    {
        return match ((int) $code) {
            1 => 'Active',
            2 => 'Disabled',
            3 => 'Unsettled',
            7 => 'Pending Risk Review',
            9 => 'In Grace Period',
            101 => 'Closed',
            default => 'Unknown',
        };
    }
}
