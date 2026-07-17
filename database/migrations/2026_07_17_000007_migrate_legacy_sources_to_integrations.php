<?php

use App\Dashboard\Models\Dashboard;
use App\Integration\Models\Integration;
use App\Menu\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the old single-source world (ghl_connections + sheet_settings.source)
 * into the new integrations model, then binds existing charts/metrics to a
 * default dashboard pointing at whichever source was active. Idempotent-ish:
 * only runs when legacy tables exist and integrations is still empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Integration::query()->exists()) {
            return; // Already migrated (or a fresh install seeded elsewhere).
        }

        $ghl = $this->migrateGhl();
        $sheets = $this->migrateSheets();

        // Which source was active drives the default binding for existing widgets.
        $activeKey = Schema::hasTable('sheet_settings')
            ? DB::table('sheet_settings')->latest('id')->value('source')
            : null;

        $active = $activeKey === 'ghl' ? $ghl : ($sheets ?? $ghl);

        $dashboard = Dashboard::create([
            'name' => 'Main Dashboard',
            'slug' => 'main',
            'position' => 0,
            'is_default' => true,
        ]);

        if (Schema::hasTable('charts')) {
            DB::table('charts')->update([
                'dashboard_id' => $dashboard->id,
                'integration_id' => $active?->id,
            ]);
        }

        if (Schema::hasTable('metrics')) {
            DB::table('metrics')->update([
                'dashboard_id' => $dashboard->id,
                'integration_id' => $active?->id,
            ]);
        }

        MenuItem::create([
            'label' => 'Main Dashboard',
            'dashboard_id' => $dashboard->id,
            'position' => 0,
        ]);
    }

    private function migrateGhl(): ?Integration
    {
        if (! Schema::hasTable('ghl_connections')) {
            return null;
        }

        $row = DB::table('ghl_connections')->latest('id')->first();

        if (! $row) {
            return null;
        }

        try {
            $token = Crypt::decryptString($row->access_token);
        } catch (Throwable) {
            $token = $row->access_token; // Fall back to raw if not encrypted.
        }

        $objects = Schema::hasColumn('sheet_settings', 'ghl_objects')
            ? json_decode(DB::table('sheet_settings')->latest('id')->value('ghl_objects') ?? 'null', true)
            : null;

        return Integration::create([
            'provider' => 'gohighlevel',
            'name' => $row->location_name ? "GoHighLevel — {$row->location_name}" : 'GoHighLevel',
            'credentials' => [
                'access_token' => $token,
                'location_id' => $row->location_id,
                'location_name' => $row->location_name,
            ],
            'config' => ['datasets' => $objects ?: ['Opportunities', 'Contacts', 'Appointments', 'Users']],
            'status' => 'connected',
            'last_synced_at' => $row->last_synced_at,
        ]);
    }

    private function migrateSheets(): ?Integration
    {
        if (! Schema::hasTable('sheet_settings')) {
            return null;
        }

        $row = DB::table('sheet_settings')->latest('id')->first();

        if (! $row || ! $row->endpoint_url) {
            return null;
        }

        return Integration::create([
            'provider' => 'google_sheets',
            'name' => 'Google Sheets',
            'credentials' => [],
            'config' => [
                'endpoint_url' => $row->endpoint_url,
                'sheet_names' => json_decode($row->sheet_names ?? '[]', true) ?: [],
            ],
            'status' => 'connected',
            'last_synced_at' => $row->last_synced_at,
        ]);
    }

    public function down(): void
    {
        // Legacy tables are left intact by up(); nothing to reverse.
    }
};
