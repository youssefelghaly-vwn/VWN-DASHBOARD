<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type');               // bar|line|pie|doughnut|scatter|horizontalBar
            $table->string('sheet');              // primary sheet name
            $table->string('label_column');       // group-by column
            $table->json('series');               // [{sheet,column,agg,label,color}]
            $table->string('aggregate')->default('count'); // count|sum|avg|min|max
            $table->unsignedInteger('limit')->default(10);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charts');
    }
};
