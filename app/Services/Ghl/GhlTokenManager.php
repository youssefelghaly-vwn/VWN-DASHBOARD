<?php

namespace App\Services\Ghl;

use App\Models\GhlConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Owns the OAuth token lifecycle: the one genuinely new operational burden
 * versus the Sheets integration. After the admin's first click, everything
 * here is invisible to them.
 */
class GhlTokenManager
{
    /** Exchange the ?code from the callback for access + refresh tokens. */
    public function exchangeCode(string $code): GhlConnection
    {
        $data = $this->requestToken([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => config('ghl.redirect_uri'),
        ]);

        return $this->persist($data);
    }

    /**
     * Return a valid access token for the given connection, refreshing first
     * if it's missing or about to expire. Every API call routes through here.
     */
    public function freshAccessToken(GhlConnection $connection): string
    {
        if ($connection->needsRefresh()) {
            $connection = $this->refresh($connection);
        }

        return $connection->access_token;
    }

    public function refresh(GhlConnection $connection): GhlConnection
    {
        $data = $this->requestToken([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
        ]);

        return $this->persist($data, $connection);
    }

    /** POST to GHL's token endpoint and return the decoded body. */
    private function requestToken(array $payload): array
    {
        $response = Http::asForm()
            ->post(config('ghl.api_base').'/oauth/token', array_merge([
                'client_id'     => config('ghl.client_id'),
                'client_secret' => config('ghl.client_secret'),
            ], $payload));

        if ($response->failed()) {
            throw new RuntimeException(
                'GHL token request failed (HTTP '.$response->status().'): '
                .($response->json('error_description') ?? $response->body())
            );
        }

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new RuntimeException('GHL did not return an access token.');
        }

        return $data;
    }

    /**
     * Persist token data onto a connection row.
     * GHL returns locationId / companyId on the exchange response, which is how
     * we learn which sub-account was connected without an extra call.
     */
    private function persist(array $data, ?GhlConnection $connection = null): GhlConnection
    {
        $attributes = [
            'access_token'     => $data['access_token'],
            'refresh_token'    => $data['refresh_token'] ?? $connection?->refresh_token,
            'token_type'       => $data['token_type'] ?? 'Bearer',
            'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 86400)),
            'scopes'           => isset($data['scope']) ? explode(' ', $data['scope']) : $connection?->scopes,
        ];

        if (! empty($data['locationId'])) {
            $attributes['location_id'] = $data['locationId'];
        }

        if (! empty($data['companyId'])) {
            $attributes['company_id'] = $data['companyId'];
        }

        if ($connection) {
            $connection->update($attributes);

            return $connection->fresh();
        }

        // First-time connect: key on location_id so a re-connect updates in place.
        return GhlConnection::updateOrCreate(
            ['location_id' => $attributes['location_id'] ?? null],
            $attributes
        );
    }
}