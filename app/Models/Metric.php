<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Metric extends Model
{
    protected $fillable = [
        'user_id', 'title', 'mode', 'sheet', 'agg', 'column',
        'filter_column', 'filter_operator', 'filter_value',
        'expression', 'variables', 'format', 'decimals',
        'subtitle', 'accent', 'position',
    ];

    protected $casts = [
        'variables' => 'array',
        'accent'    => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}