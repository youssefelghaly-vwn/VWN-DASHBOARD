<?php

namespace App\Services;

use App\Contracts\DataSource;
use App\Models\SheetSetting;
use App\Services\Concerns\AggregatesRows;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Sheets source — behaviour is UNCHANGED from before. The only edits:
 *   1. implements DataSource
 *   2. uses AggregatesRows (aggregate/buildChart/columns/schema now live in the
 *      shared trait instead of duplicated here)
 *   3. a label() method for the sidebar
 *
 * Everything about how sheets are fetched, cached, and normalized is identical,
 * so any existing charts/metrics keep working exactly as they did.
 */
class GoogleSheetService implements DataSource
{
    use AggregatesRows;

    public function label(): string
    {
        return 'Google Sheets';
    }

    public function setting(): SheetSetting
    {
        $setting = SheetSetting::current();

        if (! $setting || ! $setting->endpoint_url) {
            throw new RuntimeException('No Google Sheet endpoint configured. Go to Settings.');
        }

        return $setting;
    }

    public function all(bool $fresh = false): array
    {
        $setting = $this->setting();
        $key     = 'sheets:'.md5($setting->endpoint_url.json_encode($setting->sheet_names));

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, $setting->cache_ttl, function () use ($setting) {
            $response = Http::timeout(30)
                ->retry(2, 300)
                ->get($setting->endpoint_url, [
                    'sheets' => implode(',', $setting->sheet_names ?? []),
                ]);

            if ($response->failed()) {
                throw new RuntimeException('Sheet fetch failed: HTTP '.$response->status());
            }

            $json = $response->json();

            if (! is_array($json)) {
                throw new RuntimeException('Endpoint did not return JSON.');
            }

            if (isset($json['error'])) {
                throw new RuntimeException('Apps Script error: '.$json['error']);
            }

            $setting->forceFill(['last_synced_at' => now()])->save();

            return $json;
        });
    }

    public function sheet(string $name, bool $fresh = false): array
    {
        return data_get($this->all($fresh), $name, []);
    }
}