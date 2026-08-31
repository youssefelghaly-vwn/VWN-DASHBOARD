<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a loop draw its VALUES from one column and SCOPE its templates by
 * another.
 *
 * Before this, both were the same field, so "one sub-section per SDR" only
 * worked if the SDR already appeared in the looped dataset. Looping over
 * Users · Name gives you every SDR on the roster; scoping by Owner is what the
 * Opportunities-based templates actually filter on. An SDR with no
 * opportunities yet now gets a sub-section showing zeros instead of vanishing.
 *
 * `expanded_values` remembers the value set the last expansion materialised, so
 * an unattended re-expansion after a sync can skip the (destructive) rebuild
 * when nothing changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loop_statistics', function (Blueprint $table) {
            $table->string('scope_column')->nullable()->after('column');
            $table->json('expanded_values')->nullable()->after('templates');
        });
    }

    public function down(): void
    {
        Schema::table('loop_statistics', function (Blueprint $table) {
            $table->dropColumn(['scope_column', 'expanded_values']);
        });
    }
};
