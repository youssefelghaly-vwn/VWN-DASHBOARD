<?php

namespace App\Menu\Controllers;

use App\Dashboard\Models\Dashboard;
use App\Http\Controllers\Controller;
use App\Menu\Models\MenuItem;
use Illuminate\Http\Request;

/**
 * Lightweight menu management. Admins add dashboards (or arbitrary links) to the
 * navigation here; the nav renders from these rows, so nothing is hardcoded.
 */
class MenuController extends Controller
{
    public function index()
    {
        return view('admin.menu', [
            // Top-level items with their children eager-loaded, so the admin
            // page can render the nesting instead of a flat list. Includes
            // hidden items too (unlike MenuBuilder::tree(), which the live
            // sidebar uses) so nothing silently disappears from management.
            'items' => MenuItem::whereNull('parent_id')
                ->with(['dashboard', 'children' => fn ($q) => $q->orderBy('position')->with('dashboard')])
                ->orderBy('position')
                ->get(),
            'topLevelItems' => MenuItem::whereNull('parent_id')->orderBy('position')->get(),
            'dashboards' => Dashboard::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'dashboard_id' => ['nullable', 'integer', 'exists:dashboards,id'],
            'url' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:menu_items,id'],
        ]);

        // Menus nest one level deep, same as dashboard sections — a sub-item
        // can't itself be a parent, so pointing at one just flattens to null
        // rather than rejecting the whole request.
        if (! empty($data['parent_id'])) {
            $parent = MenuItem::find($data['parent_id']);
            if ($parent && $parent->parent_id) {
                $data['parent_id'] = $parent->parent_id;
            }
        }

        MenuItem::create($data + [
            'position' => (int) MenuItem::where('parent_id', $data['parent_id'] ?? null)->max('position') + 1,
        ]);

        return back()->with('status', 'Menu item added.');
    }

    public function destroy(MenuItem $menuItem)
    {
        $menuItem->delete();

        return back()->with('status', 'Menu item removed.');
    }
}