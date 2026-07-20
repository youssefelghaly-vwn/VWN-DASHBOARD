<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        (new GhlContactsSeeder)->run();
        (new MetaAdsSeeder)->run();
        (new DashboardsSeeder)->run();
        (new MenuItemsSeeder)->run();
    }
}
