<?php

namespace App\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One synced row of local data. Schema-less on purpose: `dataset` names the
 * collection (Contacts, Campaigns, "SDR Performance", …) and `payload` holds the
 * normalized row. This is the single table every provider writes into, so adding
 * a new integration never requires a new migration.
 */
class IntegrationRecord extends Model
{
    protected $fillable = [
        'integration_id', 'dataset', 'external_id', 'payload', 'record_date',
    ];

    protected $casts = [
        'payload' => 'array',
        'record_date' => 'date',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
