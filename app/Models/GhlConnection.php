<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GhlConnection extends Model
{
    protected $fillable = [
        'location_id', 'location_name', 'access_token', 'last_synced_at',
    ];

    protected $casts = [
        // Keeps the token unreadable at rest in the DB.
        'access_token'   => 'encrypted',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = ['access_token'];

    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }
}