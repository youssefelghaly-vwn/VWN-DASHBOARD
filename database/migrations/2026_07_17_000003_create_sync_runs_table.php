<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of each sync attempt. Every column here is shown by the generic
 * Data Health page, which is why that page needs no provider-specific logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('running'); // running|success|partial|failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('records_synced')->default(0);
            $table->unsignedInteger('failed_records')->default(0);
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable(); // per-dataset breakdown
            $table->timestamps();

            $table->index(['integration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
