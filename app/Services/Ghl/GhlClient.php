<?php

namespace App\Services\Ghl;

use App\Models\GhlConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin HTTP layer over GHL's v2 API, authenticated with a Private Integration
 * token. The token is long-lived, so there's no refresh logic.
 */
class GhlClient
{
    private function http(GhlConnection $connection): PendingRequest
    {
        return Http::baseUrl(config('ghl.api_base'))
            ->withToken($connection->access_token)
            ->withHeaders([
                'Version' => config('ghl.api_version'),
                'Accept'  => 'application/json',
            ])
            // GHL's calendar endpoint in particular can be slow; 10s was too
            // tight. Configurable via ghl.timeout, default 30s.
            ->timeout((int) config('ghl.timeout', 30))
            ->connectTimeout((int) config('ghl.connect_timeout', 10));
    }

    public function get(GhlConnection $connection, string $path, array $query = []): array
    {
        $response = $this->http($connection)->get($path, $query);

        if ($response->status() === 429) {
            $wait = (int) ($response->header('Retry-After') ?: 2);
            sleep(min($wait, 10));
            $response = $this->http($connection)->get($path, $query);
        }

        if ($response->status() === 401) {
            $body = $response->json('message') ?? $response->json('error') ?? $response->body();
            Log::warning('GHL 401', ['path' => $path, 'body' => $response->body()]);

            throw new RuntimeException(
                "GoHighLevel returned 401 on {$path}. GHL says: "
                    . (is_string($body) ? $body : json_encode($body))
                    . ' — this endpoint likely needs a scope that wasn\'t granted to the private integration.'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "GHL API error on {$path} (HTTP " . $response->status() . '): '
                    . ($response->json('message') ? json_encode($response->json('message')) : $response->body())
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Page through a list endpoint until exhausted, collecting one array key.
     * Default sends NO page-size param (most GHL endpoints 422 on `limit`);
     * opt in per-endpoint via $opts['limitParam'].
     */
    public function paginate(
        GhlConnection $connection,
        string $path,
        string $collectionKey,
        array $query = [],
        array $opts = []
    ): array {
        $out        = [];
        $pageSize   = $opts['pageSize'] ?? config('ghl.page_size', 100);
        $limitParam = $opts['limitParam'] ?? null;
        $maxPages   = config('ghl.max_pages', 100);

        if ($limitParam !== null) {
            $query[$limitParam] = $pageSize;
        }

        $lastCursor = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $body  = $this->get($connection, $path, $query);
            $chunk = $body[$collectionKey] ?? [];

            if (! $chunk) {
                break;
            }

            array_push($out, ...$chunk);

            // Terminate if this page came back short — no full page means last page.
            if (count($chunk) < $pageSize) {
                break;
            }

            $meta         = $body['meta'] ?? [];
            $startAfter   = $meta['startAfter']   ?? null;
            $startAfterId = $meta['startAfterId'] ?? null;
            $nextPageUrl  = $meta['nextPageUrl']  ?? null;

            // No next page signalled → done.
            if (! $nextPageUrl && ! $startAfterId) {
                break;
            }

            if ($startAfterId) {
                // Cursor didn't advance → GHL is echoing the last page. Stop.
                $cursor = $startAfterId . '|' . $startAfter;
                if ($cursor === $lastCursor) {
                    break;
                }
                $lastCursor = $cursor;

                $query['startAfterId'] = $startAfterId;
                if ($startAfter) {
                    $query['startAfter'] = $startAfter;
                }
                continue;
            }

            if (! $nextPageUrl) {
                break;
            }

            $query['page'] = ($query['page'] ?? 1) + 1;
        }

        return $out;
    }
}
