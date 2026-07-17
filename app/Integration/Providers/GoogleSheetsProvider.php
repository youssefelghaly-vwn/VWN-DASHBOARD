<?php

namespace App\Integration\Providers;

use App\Integration\Models\Integration;
use App\Integration\Services\SyncContext;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Sheets integration. Reads a published Apps Script endpoint that returns
 * { "SheetName": [ {col: val, ...}, ... ], ... } and writes each tab as a
 * dataset. Same behaviour as the original Sheets source, now expressed as a
 * provider that syncs into local records.
 */
class GoogleSheetsProvider implements IntegrationProvider
{
    public function key(): string
    {
        return 'google_sheets';
    }

    public function label(): string
    {
        return 'Google Sheets';
    }

    public function connect(Integration $integration, array $credentials): void
    {
        $url = trim($credentials['endpoint_url'] ?? '');

        if (! str_starts_with($url, 'https://script.google.com/')) {
            throw new RuntimeException('A published Google Apps Script URL is required.');
        }

        $names = collect(explode(',', $credentials['sheet_names'] ?? ''))
            ->map(fn ($n) => trim($n))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! $names) {
            throw new RuntimeException('At least one sheet/tab name is required.');
        }

        $integration->fill([
            'provider' => $this->key(),
            'name' => $credentials['name'] ?? 'Google Sheets',
            'credentials' => [],
            'config' => array_merge($integration->config ?? [], [
                'endpoint_url' => $url,
                'sheet_names' => $names,
            ]),
        ]);

        // Validate by fetching once.
        $this->fetch($integration);

        $integration->status = 'connected';
        $integration->save();
    }

    public function disconnect(Integration $integration): void
    {
        // Nothing external to tear down.
    }

    public function sync(Integration $integration, SyncContext $context): void
    {
        $data = $this->fetch($integration);

        foreach ($data as $sheet => $rows) {
            if (! is_array($rows)) {
                continue;
            }

            $context->write($sheet, array_map(fn ($row) => [
                'external_id' => null,
                'payload' => is_array($row) ? $row : [],
            ], $rows));
        }
    }

    public function schema(Integration $integration): array
    {
        $out = [];

        foreach ($integration->setting('sheet_names', []) as $sheet) {
            $rows = $integration->rows($sheet);
            $out[$sheet] = $rows ? array_keys($rows[0]) : [];
        }

        return $out;
    }

    /** Fetch the raw {sheet: rows} payload from the Apps Script endpoint. */
    private function fetch(Integration $integration): array
    {
        $url = $integration->setting('endpoint_url');
        $names = $integration->setting('sheet_names', []);

        if (! $url) {
            throw new RuntimeException('No Google Sheet endpoint configured.');
        }

        $response = Http::timeout(30)->retry(2, 300)->get($url, [
            'sheets' => implode(',', $names),
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

        return $json;
    }
}
