<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard sections — named dividers that group metrics + charts, with one
 * optional level of nesting (a section can have sub-sections via parent_id).
 * Charts and metrics carry a nullable section_id (null = ungrouped, rendered
 * above the sections). Charts also gain width/height so their size on the grid
 * is controllable.
 *
 * section_id on charts/metrics is a plain nullable column (no DB-level foreign
 * key) so the ALTERs stay portable to SQLite; the app nulls dangling references
 * itself when a section is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('dashboard_sections')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('charts', function (Blueprint $table) {
            $table->unsignedBigInteger('section_id')->nullable()->after('dashboard_id');
            $table->string('width')->default('full')->after('limit');   // full|twothirds|half|third
            $table->unsignedInteger('height')->nullable()->after('width'); // canvas px; null = default
        });

        Schema::table('metrics', function (Blueprint $table) {
            $table->unsignedBigInteger('section_id')->nullable()->after('dashboard_id');
        });
    }

    public function down(): void
    {
        Schema::table('metrics', function (Blueprint $table) {
            $table->dropColumn('section_id');
        });

        Schema::table('charts', function (Blueprint $table) {
            $table->dropColumn(['section_id', 'width', 'height']);
        });

        Schema::dropIfExists('dashboard_sections');
    }
};
