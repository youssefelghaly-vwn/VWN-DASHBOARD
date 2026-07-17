<?php

namespace App\Menu\Models;

use App\Dashboard\Models\Dashboard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One navigation entry. Points at a dashboard (by id), a named route, or a raw
 * URL — so a new dashboard joins the menu by inserting a row, never by editing
 * the nav template or adding a route.
 */
class MenuItem extends Model
{
    protected $fillable = [
        'label', 'dashboard_id', 'url', 'route', 'parent_id', 'position', 'is_visible',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
    ];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /** Resolve the href this item links to, without hardcoding anything. */
    public function href(): string
    {
        if ($this->dashboard_id && $this->dashboard) {
            return route('admin.dashboards.show', $this->dashboard->slug);
        }

        if ($this->route) {
            return route($this->route);
        }

        return $this->url ?? '#';
    }
}
