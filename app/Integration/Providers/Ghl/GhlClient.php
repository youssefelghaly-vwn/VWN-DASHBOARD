<?php

namespace App\Integration\Providers\Ghl;

use App\Integration\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin HTTP layer over GoHighLevel's v2 API, authenticated with a Private
 * Integration token read from the integration's credentials. The token is
 * long-lived, so there's no refresh logic.
 */
class GhlClient
{
    private function config(string $key, mixed $default = null): mixed
    {
        return config("integrations.gohighlevel.{$key}", $default);
    }

    private function http(Integration $integration): PendingRequest
    {
        return Http::baseUrl($this->config('api_base'))
            ->withToken($integration->credential('access_token'))
            ->withHeaders([
                'Version' => $this->config('api_version'),
                'Accept' => 'application/json',
            ])
            ->timeout((int) $this->config('timeout', 30))
            ->connectTimeout((int) $this->config('connect_timeout', 10));
    }

    public function get(Integration $integration, string $path, array $query = []): array
    {
        $response = $this->send($integration, 'get', $path, $query);

        if ($response->status() === 429) {
            $wait = (int) ($response->header('Retry-After') ?: 2);
            sleep(min($wait, 10));
            $response = $this->send($integration, 'get', $path, $query);
        }

        return $this->decode($response, $path);
    }

    public function post(Integration $integration, string $path, array $body = []): array
    {
        $response = $this->send($integration, 'post', $path, $body);

        if ($response->status() === 429) {
            $wait = (int) ($response->header('Retry-After') ?: 2);
            sleep(min($wait, 10));
            $response = $this->send($integration, 'post', $path, $body);
        }

        return $this->decode($response, $path);
    }

    private function send(Integration $integration, string $method, string $path, array $payload): Response
    {
        $attempts = max(0, (int) $this->config('retries', 2));
        $lastError = null;

        for ($try = 0; $try <= $attempts; $try++) {
            try {
                return $this->http($integration)->{$method}($path, $payload);
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
                    .(is_string($body) ? $body : json_encode($body))
                    .' — this endpoint likely needs a scope that wasn\'t granted to the private integration.'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "GHL API error on {$path} (HTTP ".$response->status().'): '
                    .($response->json('message') ? json_encode($response->json('message')) : $response->body())
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Page through a list endpoint until exhausted, collecting one array key.
     */
    public function paginate(
        Integration $integration,
        string $path,
        string $collectionKey,
        array $query = [],
        array $opts = []
    ): array {
        $out = [];
        $pageSize = $opts['pageSize'] ?? $this->config('page_size', 100);
        $limitParam = $opts['limitParam'] ?? null;
        $maxPages = $this->config('max_pages', 100);

        if ($limitParam !== null) {
            $query[$limitParam] = $pageSize;
        }

        $lastCursor = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $body = $this->get($integration, $path, $query);
            $chunk = $body[$collectionKey] ?? [];

            if (! $chunk) {
                break;
            }

            array_push($out, ...$chunk);

            if (count($chunk) < $pageSize) {
                break;
            }

            $meta = $body['meta'] ?? [];
            $startAfter = $meta['startAfter'] ?? null;
            $startAfterId = $meta['startAfterId'] ?? null;
            $nextPageUrl = $meta['nextPageUrl'] ?? null;

            if (! $nextPageUrl && ! $startAfterId) {
                break;
            }

            if ($startAfterId) {
                $cursor = $startAfterId.'|'.$startAfter;
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
     * Contacts via POST /contacts/search. Pages with searchAfter cursors.
     * When $sinceMs is given, results are sorted newest-first and paging stops as
     * soon as a contact at/older than that watermark is reached — a cheap
     * incremental delta so only the first sync walks the whole list.
     *
     * @return array{contacts: array<int, array<string, mixed>>, total: int}
     */
    public function searchContacts(Integration $integration, ?int $sinceMs = null, array $opts = []): array
    {
        $pageSize = (int) ($opts['pageSize'] ?? $this->config('page_size', 100));
        $maxPages = (int) $this->config('max_pages', 100);

        $body = [
            'locationId' => $integration->credential('location_id'),
            'pageLimit' => $pageSize,
            'sort' => [['field' => 'dateUpdated', 'direction' => 'desc']],
        ];

        $out = [];
        $total = 0;
        $searchAfter = null;

        for ($page = 0; $page < $maxPages; $page++) {
            if ($searchAfter !== null) {
                $body['searchAfter'] = $searchAfter;
            }

            $resp = $this->post($integration, '/contacts/search', $body);
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

            if ($reachedWatermark || count($chunk) < $pageSize) {
                break;
            }

            $last = end($chunk);
            $searchAfter = $last['searchAfter'] ?? null;

            if (! $searchAfter) {
                break;
            }
        }

        return ['contacts' => $out, 'total' => $total];
    }

    /** A contact's dateUpdated as epoch-ms, tolerant of ISO strings or ms ints. */
    public function contactUpdatedMs(array $contact): int
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
