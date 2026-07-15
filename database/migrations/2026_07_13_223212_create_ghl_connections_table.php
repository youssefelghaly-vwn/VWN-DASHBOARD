<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private Integration token model (API v2.0) — NOT OAuth.
 *
 * A private integration gives one long-lived token scoped to one location.
 * No refresh, no expiry, no callback. The admin pastes the token + their
 * location id, and we're connected.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ghl_connections', function (Blueprint $table) {
            $table->id();

            $table->string('location_id');
            $table->string('location_name')->nullable();

            // The private integration token (starts with "pit-"). Encrypted at rest.
            $table->text('access_token');

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ghl_connections');
    }
};