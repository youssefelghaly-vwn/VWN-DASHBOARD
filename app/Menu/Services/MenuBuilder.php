<?php

namespace App\Menu\Services;

use App\Menu\Models\MenuItem;
use Illuminate\Support\Collection;

/**
 * Builds the navigation tree from the database. The nav template just renders
 * whatever this returns, so adding a dashboard to the menu is a data change.
 */
class MenuBuilder
{
    /** Top-level visible items with their children eager-loaded. */
    public function tree(): Collection
    {
        return MenuItem::query()
            ->whereNull('parent_id')
            ->where('is_visible', true)
            ->with(['dashboard', 'children' => fn ($q) => $q->where('is_visible', true)->with('dashboard')])
            ->orderBy('position')
            ->get();
    }
}
