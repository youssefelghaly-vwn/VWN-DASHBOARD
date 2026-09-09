<?php

namespace App\Integration\Providers\CloudTalk;

use App\Integration\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin HTTP layer over the CloudTalk core API (my.cloudtalk.io/api), which uses
 * HTTP Basic auth with an Access Key ID / Secret pair generated under
 * Account → Settings → API Keys. The keys are long-lived, so there's no refresh.
 */
class CloudTalkClient
{
    private function config(string $key, mixed $default = null): mixed
    {
        return config("integrations.cloudtalk.{$key}", $default);
    }

    private function http(Integration $integration): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->config('api_base'), '/'))
            ->withBasicAuth(
                (string) $integration->credential('access_key_id'),
                (string) $integration->credential('access_key_secret')
            )
            ->withHeaders([
                'Accept' => 'application/json',
                // Cloudflare fronts *.cloudtalk.io and answers default library
                // user agents with its bot challenge (error 1010) rather than a
                // real response — which surfaces as unparseable HTML, not a 4xx.
                'User-Agent' => (string) $this->config('user_agent'),
            ])
            ->timeout((int) $this->config('timeout', 30))
            ->connectTimeout((int) $this->config('connect_timeout', 10));
    }

    /**
     * One GET, returning the decoded body.
     *
     * Rate limiting is 60 requests/minute per company, shared across every key
     * on the account — so a 429 is a queue, not a failure: wait out the window
     * the server names and try once more before giving up.
     */
    public function get(Integration $integration, string $path, array $query = []): array
    {
        $attempts = max(0, (int) $this->config('retries', 2));
        $lastError = null;

        for ($try = 0; $try <= $attempts; $try++) {
            try {
                $response = $this->http($integration)->get($path, $query);

                if ($response->status() === 429) {
                    sleep(min((int) ($response->header('Retry-After') ?: 5), 30));
                    $response = $this->http($integration)->get($path, $query);
                }

                if ($response->status() === 401 || $response->status() === 403) {
                    throw new RuntimeException(
                        "CloudTalk rejected the credentials on {$path} (HTTP ".$response->status().'). '
                        .'Check the Access Key ID and Secret, that the key belongs to an Admin user, '
                        .'and that the account is on the Essential plan or above.'
                    );
                }

                if ($response->failed()) {
                    throw new RuntimeException(
                        "CloudTalk API error on {$path} (HTTP ".$response->status().'): '
                        .($response->json('responseData.message') ?? $response->json('message') ?? $this->snippet($response->body()))
                    );
                }

                return $response->json() ?? [];
            } catch (ConnectionException $e) {
                $lastError = $e;
                Log::warning('CloudTalk connection failed, will retry', ['path' => $path, 'try' => $try + 1]);

                if ($try < $attempts) {
                    sleep(min(2 ** ($try + 1), 8));
                }
            }
        }

        throw $lastError;
    }

    /**
     * Page through an `index.json` list endpoint, collecting responseData.data.
     *
     * Every core-API list endpoint answers with the same envelope:
     *   {"responseData": {"data": [...], "itemsCount": n, "pageCount": n, "page": n}}
     *
     * $maxPages bounds one call so a large account cannot spend the whole
     * minute's rate-limit budget in a single dataset.
     *
     * @return array{data: array<int, mixed>, itemsCount: int, pageCount: int}
     */
    public function paginate(Integration $integration, string $path, array $query = [], ?int $maxPages = null): array
    {
        $query['limit'] = $query['limit'] ?? (int) $this->config('page_size', 100);
        $maxPages ??= (int) $this->config('max_pages', 20);

        $out = [];
        $itemsCount = 0;
        $pageCount = 0;
        $page = (int) ($query['page'] ?? 1);

        for ($fetched = 0; $fetched < $maxPages; $fetched++) {
            $query['page'] = $page;

            $envelope = $this->get($integration, $path, $query)['responseData'] ?? [];
            $batch = $envelope['data'] ?? [];

            $itemsCount = (int) ($envelope['itemsCount'] ?? $itemsCount);
            $pageCount = (int) ($envelope['pageCount'] ?? $pageCount);

            if (! $batch) {
                break;
            }

            array_push($out, ...$batch);
            $page++;

            if ($pageCount > 0 && $page > $pageCount) {
                break;
            }
        }

        return ['data' => $out, 'itemsCount' => $itemsCount, 'pageCount' => $pageCount];
    }

    /** Cloudflare error pages are whole HTML documents; don't paste one into a health record. */
    private function snippet(string $body): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        return mb_substr($clean, 0, 200) ?: 'empty response body';
    }
}
