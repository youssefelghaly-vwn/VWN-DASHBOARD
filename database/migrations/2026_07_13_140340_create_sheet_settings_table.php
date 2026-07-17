<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheet_settings', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint_url');
            $table->json('sheet_names');          // ["Output","Data","Leads"]
            $table->unsignedInteger('cache_ttl')->default(60);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_settings');
    }
};
