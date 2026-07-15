<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the admin choose WHICH GoHighLevel objects the dashboard pulls
 * (Opportunities, Contacts, Appointments, Users) instead of always fetching
 * all four. NULL preserves the old behaviour — pull everything — so existing
 * installs keep working until they narrow the selection.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sheet_settings', function (Blueprint $table) {
            $table->json('ghl_objects')->nullable()->after('sheet_names');
        });
    }

    public function down(): void
    {
        Schema::table('sheet_settings', function (Blueprint $table) {
            $table->dropColumn('ghl_objects');
        });
    }
};
