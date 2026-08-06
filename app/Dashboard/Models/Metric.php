<?php

namespace App\Dashboard\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A metric (single number) widget. Like charts, it carries a default
 * integration and each simple config / formula variable may point at its own
 * integration + dataset.
 */
class Metric extends Model
{
    protected $fillable = [
        'user_id', 'dashboard_id', 'section_id', 'integration_id', 'title', 'mode', 'sheet',
        'agg', 'column', 'filter_column', 'filter_operator', 'filter_value', 'filters',
        'expression', 'variables', 'format', 'decimals', 'subtitle', 'accent', 'position',
    ];

    protected $casts = [
        'variables' => 'array',
        'filters' => 'array',
        'accent' => 'boolean',
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
