<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loop statistics — a generator that fans a set of template metrics/charts out
 * across every distinct value of a column (e.g. Owner). Expanding a loop
 * creates one parent section (the loop's name), a sub-section per value, and a
 * copy of each template inside it with an injected "{column} = value" filter.
 *
 * The definition is persisted so the loop can be re-expanded when the data
 * gains new values, and deleted cleanly. Generated widgets carry loop_id so
 * they can be found and removed on refresh/delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('section_id')->nullable(); // the parent section it owns
            $table->string('name');
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('dataset');
            $table->string('column');                 // the column looped over (e.g. Owner)
            $table->string('value_operator')->nullable(); // optional filter on which values to include
            $table->string('value_match')->nullable();
            $table->json('templates');                // { metrics: [...], charts: [...] }
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('charts', function (Blueprint $table) {
            $table->unsignedBigInteger('loop_id')->nullable()->after('section_id');
        });

        Schema::table('metrics', function (Blueprint $table) {
            $table->unsignedBigInteger('loop_id')->nullable()->after('section_id');
        });
    }

    public function down(): void
    {
        Schema::table('metrics', function (Blueprint $table) {
            $table->dropColumn('loop_id');
        });

        Schema::table('charts', function (Blueprint $table) {
            $table->dropColumn('loop_id');
        });

        Schema::dropIfExists('loop_statistics');
    }
};
