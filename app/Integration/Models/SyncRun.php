<?php

namespace App\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of one sync attempt. This is the single source of truth behind the
 * generic Data Health page — every field it shows (last sync, status, records
 * synced, failed records, last error) is a column here, so Data Health needs no
 * provider-specific code.
 */
class SyncRun extends Model
{
    public const RUNNING = 'running';

    public const SUCCESS = 'success';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    protected $fillable = [
        'integration_id', 'status', 'started_at', 'finished_at',
        'records_synced', 'failed_records', 'last_error', 'meta',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'records_synced' => 'integer',
        'failed_records' => 'integer',
        'meta' => 'array',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function durationMs(): ?int
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return (int) $this->started_at->diffInMilliseconds($this->finished_at);
    }
}
