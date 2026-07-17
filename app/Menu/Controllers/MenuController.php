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
            'items' => MenuItem::with('dashboard')->orderBy('position')->get(),
            'dashboards' => Dashboard::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'dashboard_id' => ['nullable', 'integer', 'exists:dashboards,id'],
            'url' => ['nullable', 'string', 'max:255'],
        ]);

        MenuItem::create($data + [
            'position' => (int) MenuItem::max('position') + 1,
        ]);

        return back()->with('status', 'Menu item added.');
    }

    public function destroy(MenuItem $menuItem)
    {
        $menuItem->delete();

        return back()->with('status', 'Menu item removed.');
    }
}
