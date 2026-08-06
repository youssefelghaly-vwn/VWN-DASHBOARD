<?php

namespace App\Dashboard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A titled divider on a dashboard that groups metrics + charts. Sections nest
 * one level deep: a top-level section (parent_id = null) may own sub-sections
 * (parent_id = the top section). Charts/metrics reference a section via their
 * nullable section_id (null = ungrouped).
 */
class Section extends Model
{
    protected $table = 'dashboard_sections';

    protected $fillable = ['dashboard_id', 'parent_id', 'title', 'position'];

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
}
