<?php

namespace Database\Seeders;

use App\Dashboard\Models\Dashboard;
use App\Menu\Models\MenuItem;
use Illuminate\Database\Seeder;

/** Puts the two seeded dashboards in the nav. */
class MenuItemsSeeder extends Seeder
{
    public function run(): void
    {
        $ghl = Dashboard::where('slug', 'ghl-leads')->first();
        $meta = Dashboard::where('slug', 'meta-ads-performance')->first();

        if ($ghl) {
            MenuItem::updateOrCreate(
                ['label' => 'GHL — Leads', 'dashboard_id' => $ghl->id],
                ['position' => 1, 'is_visible' => true]
            );
        }

        if ($meta) {
            MenuItem::updateOrCreate(
                ['label' => 'Meta Ads — Performance', 'dashboard_id' => $meta->id],
                ['position' => 2, 'is_visible' => true]
            );
        }
    }
}
