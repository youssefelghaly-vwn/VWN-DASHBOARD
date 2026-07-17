<?php

namespace App\Integration\Providers;

use App\Integration\Models\Integration;
use App\Integration\Services\SyncContext;

/**
 * The single surface every integration exposes. GoHighLevel, Google Sheets,
 * Meta Ads (and anything added later) all implement this and nothing more.
 *
 * Because the sync job, Data Health, and the dashboards only ever talk to this
 * contract and to the generic local tables, adding an integration means writing
 * one provider — no controller, job, health page, or dashboard change.
 */
interface IntegrationProvider
{
    /** Stable key used in config and the `integrations.provider` column. */
    public function key(): string;

    /** Human label shown in the UI. */
    public function label(): string;

    /**
     * Validate + persist credentials for a new/updated connection. Should throw
     * with a clear message if the credentials don't work, and set the model's
     * name/credentials/status/config on success.
     */
    public function connect(Integration $integration, array $credentials): void;

    /** Tear down anything external if needed (usually a no-op). */
    public function disconnect(Integration $integration): void;

    /**
     * Pull data from the external system and hand it to the context, which
     * writes it to the local `integration_records` table and tracks counts and
     * errors for Data Health. Providers never touch the database directly.
     */
    public function sync(Integration $integration, SyncContext $context): void;

    /**
     * ['Dataset' => ['col1','col2', ...], ...] — the datasets and columns this
     * integration can expose, used to drive the chart/metric builder dropdowns.
     */
    public function schema(Integration $integration): array;
}
