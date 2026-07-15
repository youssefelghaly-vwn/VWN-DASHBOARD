<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SheetSetting extends Model
{
    protected $fillable = [
        'source', 'endpoint_url', 'sheet_names', 'cache_ttl', 'last_synced_at',
    ];

    protected $casts = [
        'sheet_names'    => 'array',
        'last_synced_at' => 'datetime',
    ];

    protected $attributes = [
        'source' => 'sheets',
    ];

    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }
}