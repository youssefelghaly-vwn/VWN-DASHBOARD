<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chart extends Model
{
    protected $fillable = [
        'user_id', 'title', 'type', 'sheet', 'label_column',
        'series', 'aggregate', 'limit', 'position', 'is_system',
    ];

    protected $casts = [
        'series'    => 'array',
        'is_system' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}