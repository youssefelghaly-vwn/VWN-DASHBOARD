<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "sheet" is one Excel-style tab in the read-only data workspace: it points at
 * a single synced dataset (from any integration) and stores the user's view of
 * it — filters, sorting, grouping, VLOOKUP columns, and in-sheet charts — in a
 * JSON `config`. No source data is ever written here; sheets only describe how
 * to look at rows that already live in integration_records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            // Source table: which integration + dataset this sheet reads.
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dataset');
            // View state: filters / sort / group / lookups / charts.
            $table->json('config')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheets');
    }
};
