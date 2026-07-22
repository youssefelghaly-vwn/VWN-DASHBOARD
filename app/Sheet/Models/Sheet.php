<?php

namespace App\Sheet\Models;

use App\Integration\Models\Integration;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tab in the Sheets workspace. Binds a single synced dataset
 * (integration_id + dataset) to a saved view (`config`: filters, sort, group,
 * lookups, charts). Read-only over the data — it never mutates synced rows.
 */
class Sheet extends Model
{
    protected $fillable = [
        'user_id', 'name', 'integration_id', 'dataset', 'config', 'position',
    ];

    protected $casts = [
        'config' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
