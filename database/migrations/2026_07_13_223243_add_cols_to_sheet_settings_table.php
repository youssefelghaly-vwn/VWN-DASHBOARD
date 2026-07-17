<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The active-source switch lives on sheet_settings so there's a single row
 * that answers "where does the dashboard read from right now?". Sheets keeps
 * working exactly as before when source = 'sheets' (the default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheet_settings', function (Blueprint $table) {
            $table->string('source')->default('sheets')->after('id'); // sheets | ghl
        });

        // Existing endpoint/sheet columns become nullable, since a GHL-only
        // install won't have a sheet endpoint configured.
        Schema::table('sheet_settings', function (Blueprint $table) {
            $table->string('endpoint_url')->nullable()->change();
            $table->json('sheet_names')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sheet_settings', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
