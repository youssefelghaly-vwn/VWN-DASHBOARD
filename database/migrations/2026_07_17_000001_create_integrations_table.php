<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per connected integration instance, provider-agnostic. `credentials`
 * is encrypted at rest; `config` holds non-secret per-instance options.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider');            // gohighlevel | google_sheets | meta_ads
            $table->string('name');
            $table->text('credentials')->nullable(); // encrypted JSON
            $table->json('config')->nullable();
            $table->string('status')->default('disconnected'); // connected | disconnected | error
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
