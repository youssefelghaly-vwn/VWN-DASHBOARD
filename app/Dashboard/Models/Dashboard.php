<?php

namespace App\Dashboard\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A named collection of widgets (charts + metrics). The app supports many
 * dashboards — Leads, Sales, Marketing, SDR — each combining any integrations.
 */
class Dashboard extends Model
{
    protected $fillable = [
        'user_id', 'name', 'slug', 'position', 'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Dashboard $dashboard) {
            if (! $dashboard->slug) {
                $dashboard->slug = static::uniqueSlug($dashboard->name);
            }
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'dashboard';
        $slug = $base;
        $n = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('position');
    }

    public function loops(): HasMany
    {
        return $this->hasMany(LoopStatistic::class)->orderBy('position');
    }

    public function charts(): HasMany
    {
        return $this->hasMany(Chart::class)->orderBy('position');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(Metric::class)->orderBy('position');
    }
}
