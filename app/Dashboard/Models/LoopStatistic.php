<?php

namespace App\Dashboard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved "loop" definition: which column to fan out over, an optional filter
 * on which values to include, and the template metrics/charts to reproduce for
 * each value. Expanding it materialises real Section + Chart + Metric rows
 * (tagged with this loop's id) so they render through the normal pipeline.
 */
class LoopStatistic extends Model
{
    protected $table = 'loop_statistics';

    protected $fillable = [
        'dashboard_id', 'section_id', 'name', 'integration_id', 'dataset',
        'column', 'value_operator', 'value_match', 'templates', 'position',
    ];

    protected $casts = [
        'templates' => 'array',
    ];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }
}
