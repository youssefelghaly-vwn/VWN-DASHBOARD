<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bind existing charts + metrics to a dashboard and to a default integration.
 * The per-series `integration_id` / `dataset` inside the JSON config takes
 * precedence when set (that's what enables cross-integration charts); these
 * columns are the fallback default for a widget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charts', function (Blueprint $table) {
            $table->foreignId('dashboard_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->after('dashboard_id')->constrained()->nullOnDelete();
        });

        Schema::table('metrics', function (Blueprint $table) {
            $table->foreignId('dashboard_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->after('dashboard_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('charts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dashboard_id');
            $table->dropConstrainedForeignId('integration_id');
        });

        Schema::table('metrics', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dashboard_id');
            $table->dropConstrainedForeignId('integration_id');
        });
    }
};
