<?php

namespace App\Integration\Providers\Meta;

use App\Integration\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin HTTP layer over the Meta Marketing (Graph) API, authenticated with a
 * long-lived access token read from the integration's credentials.
 */
class MetaClient
{
    private function config(string $key, mixed $default = null): mixed
    {
        return config("integrations.meta_ads.{$key}", $default);
    }

    private function base(): string
    {
        return rtrim($this->config('api_base'), '/').'/'.$this->config('api_version');
    }

    /** A single GET against a Graph node, returning the decoded body. */
    public function get(Integration $integration, string $path, array $query = []): array
    {
        $query['access_token'] = $integration->credential('access_token');

        $attempts = max(0, (int) $this->config('retries', 2));
        $lastError = null;

        for ($try = 0; $try <= $attempts; $try++) {
            try {
                $response = Http::baseUrl($this->base())
                    ->timeout((int) $this->config('timeout', 30))
                    ->connectTimeout((int) $this->config('connect_timeout', 10))
                    ->acceptJson()
                    ->get('/'.ltrim($path, '/'), $query);

                if ($response->failed()) {
                    $err = $response->json('error.message') ?? $response->body();

                    throw new RuntimeException("Meta API error on {$path} (HTTP ".$response->status().'): '.$err);
                }

                return $response->json() ?? [];
            } catch (ConnectionException $e) {
                $lastError = $e;
                Log::warning('Meta connection failed, will retry', ['path' => $path, 'try' => $try + 1]);

                if ($try < $attempts) {
                    sleep(min(2 ** ($try + 1), 8));
                }
            }
        }

        throw $lastError;
    }

    /**
     * Page through a Graph edge, following paging.next cursors, collecting the
     * `data` array. Bounded by max_pages.
     */
    public function paginate(Integration $integration, string $path, array $query = []): array
    {
        $query['limit'] = $query['limit'] ?? (int) $this->config('page_size', 100);
        $maxPages = (int) $this->config('max_pages', 100);

        $out = [];
        $after = null;

        for ($page = 0; $page < $maxPages; $page++) {
            if ($after !== null) {
                $query['after'] = $after;
            }

            $body = $this->get($integration, $path, $query);
            $data = $body['data'] ?? [];

            if (! $data) {
                break;
            }

            array_push($out, ...$data);

            $after = $body['paging']['cursors']['after'] ?? null;

            if (! $after || empty($body['paging']['next'])) {
                break;
            }
        }

        return $out;
    }
}
