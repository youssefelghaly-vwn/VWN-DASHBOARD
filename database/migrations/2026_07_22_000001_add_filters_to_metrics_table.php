<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metrics', function (Blueprint $table) {
            // Extra {column, operator, value} conditions ANDed on top of the
            // legacy single filter_column/operator/value — used for the
            // Pipeline/Stage cascading picker (both filters must hold at once).
            $table->json('filters')->nullable()->after('filter_value');
        });
    }

    public function down(): void
    {
        Schema::table('metrics', function (Blueprint $table) {
            $table->dropColumn('filters');
        });
    }
};
