<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single local data store every integration syncs into. Schema-less by
 * design: `dataset` names the collection and `payload` holds the normalized row,
 * so adding a new integration never requires a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('dataset');
            $table->string('external_id')->nullable();
            $table->json('payload');
            $table->date('record_date')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'dataset']);
            $table->index(['integration_id', 'dataset', 'record_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_records');
    }
};
