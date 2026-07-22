<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charts', function (Blueprint $table) {
            // Chart-level {column, operator, value} conditions ANDed together,
            // applied to every series before aggregation.
            $table->json('filters')->nullable()->after('series');
        });
    }

    public function down(): void
    {
        Schema::table('charts', function (Blueprint $table) {
            $table->dropColumn('filters');
        });
    }
};
