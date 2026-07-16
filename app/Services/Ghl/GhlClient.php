<?php

namespace App\Services\Ghl;

use App\Models\GhlConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
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
        $response = $this->send($connection, 'get', $path, $query);

        if ($response->status() === 429) {
            $wait = (int) ($response->header('Retry-After') ?: 2);
            sleep(min($wait, 10));
            $response = $this->send($connection, 'get', $path, $query);
        }

        return $this->decode($response, $path);
    }

    /**
     * POST to a GHL endpoint. GHL's v2 search endpoints (e.g. /contacts/search)
     * take their filters in the body, so a JSON POST is required rather than a
     * query string.
     */
    public function post(GhlConnection $connection, string $path, array $body = []): array
    {
        $response = $this->send($connection, 'post', $path, $body);

        if ($response->status() === 429) {
            $wait = (int) ($response->header('Retry-After') ?: 2);
            sleep(min($wait, 10));
            $response = $this->send($connection, 'post', $path, $body);
        }

        return $this->decode($response, $path);
    }

    /**
     * Fire one request, retrying only low-level connection failures — a cURL 28
     * timeout or dropped socket, surfaced by Guzzle as ConnectionException. HTTP
     * error *statuses* (401/429/5xx) are NOT retried here; decode() handles
     * those. Back-off is 2s, 4s, 8s (capped).
     */
    private function send(GhlConnection $connection, string $method, string $path, array $payload): Response
    {
        $attempts = max(0, (int) config('ghl.retries', 2));
        $lastError = null;

        for ($try = 0; $try <= $attempts; $try++) {
            try {
                return $this->http($connection)->{$method}($path, $payload);
            } catch (ConnectionException $e) {
                $lastError = $e;
                Log::warning('GHL connection failed, will retry', [
                    'path' => $path, 'try' => $try + 1, 'error' => $e->getMessage(),
                ]);

                if ($try < $attempts) {
                    sleep(min(2 ** ($try + 1), 8));
                }
            }
        }

        throw $lastError;
    }

    private function decode(Response $response, string $path): array
    {
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

    /**
     * Contacts via POST /contacts/search — GHL's faster, more reliable bulk
     * path than the legacy GET /contacts/ (which stalls and cURL-28s on deep
     * pages of large accounts). Pages with searchAfter cursors.
     *
     * When $sinceMs is given, results are sorted newest-first and paging stops
     * as soon as a contact at/older than that watermark is reached — a cheap
     * incremental delta, so only the FIRST sync walks the whole list.
     *
     * @return array{contacts: array<int, array<string, mixed>>, total: int}
     */
    public function searchContacts(GhlConnection $connection, ?int $sinceMs = null, array $opts = []): array
    {
        $pageSize = (int) ($opts['pageSize'] ?? config('ghl.page_size', 100));
        $maxPages = (int) config('ghl.max_pages', 100);

        $body = [
            'locationId' => $connection->location_id,
            'pageLimit'  => $pageSize,
            // Newest-first so an incremental run can stop early; a full run is
            // order-agnostic and reads every page anyway.
            'sort' => [['field' => 'dateUpdated', 'direction' => 'desc']],
        ];

        $out         = [];
        $total       = 0;
        $searchAfter = null;

        for ($page = 0; $page < $maxPages; $page++) {
            if ($searchAfter !== null) {
                $body['searchAfter'] = $searchAfter;
            }

            $resp  = $this->post($connection, '/contacts/search', $body);
            $chunk = $resp['contacts'] ?? [];
            $total = (int) ($resp['total'] ?? $total);

            if (! $chunk) {
                break;
            }

            $reachedWatermark = false;

            foreach ($chunk as $contact) {
                if ($sinceMs !== null && $this->contactUpdatedMs($contact) <= $sinceMs) {
                    $reachedWatermark = true;
                    break;
                }
                $out[] = $contact;
            }

            // Everything from here on is already in our snapshot, or this was a
            // short (final) page — either way we're done.
            if ($reachedWatermark || count($chunk) < $pageSize) {
                break;
            }

            $last        = end($chunk);
            $searchAfter = $last['searchAfter'] ?? null;

            // No cursor to advance on → nothing more to fetch.
            if (! $searchAfter) {
                break;
            }
        }

        return ['contacts' => $out, 'total' => $total];
    }

    /**
     * Total number of contacts matching $filters, computed server-side via the
     * search endpoint's `total` — one request, no pagination. Empty $filters
     * counts every contact in the location.
     */
    public function countContacts(GhlConnection $connection, array $filters = []): int
    {
        $body = [
            'locationId' => $connection->location_id,
            'pageLimit'  => 1,
        ];

        if ($filters) {
            $body['filters'] = $filters;
        }

        $resp = $this->post($connection, '/contacts/search', $body);

        return (int) ($resp['total'] ?? 0);
    }

    /** A contact's dateUpdated as epoch-ms, tolerant of ISO strings or ms ints. */
    private function contactUpdatedMs(array $contact): int
    {
        $raw = $contact['dateUpdated'] ?? $contact['dateAdded'] ?? null;

        if ($raw === null || $raw === '') {
            return 0;
        }

        if (is_numeric($raw)) {
            return (int) $raw;
        }

        try {
            return (int) Carbon::parse($raw)->getTimestampMs();
        } catch (\Throwable) {
            return 0;
        }
    }
}
