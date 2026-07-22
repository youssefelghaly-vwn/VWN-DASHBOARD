<?php

namespace App\Dashboard\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chart widget. `integration_id` + `sheet` (dataset) are the widget's default
 * source; each entry in `series` may override integration_id/dataset, which is
 * what lets a single chart combine data from several integrations.
 */
class Chart extends Model
{
    protected $fillable = [
        'user_id', 'dashboard_id', 'integration_id', 'title', 'type', 'sheet',
        'label_column', 'series', 'filters', 'aggregate', 'limit', 'position', 'is_system',
    ];

    protected $casts = [
        'series' => 'array',
        'filters' => 'array',
        'is_system' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }
}
