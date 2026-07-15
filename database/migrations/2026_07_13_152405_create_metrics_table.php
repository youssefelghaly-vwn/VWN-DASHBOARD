<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('mode');              // simple | formula
            $table->string('sheet')->nullable(); // simple mode only
            $table->string('agg')->nullable();   // count|sum|avg|min|max|count_if|percent_if
            $table->string('column')->nullable();
            $table->string('filter_column')->nullable();
            $table->string('filter_operator')->nullable(); // eq|neq|contains|not_contains|gt|lt|not_empty|empty
            $table->string('filter_value')->nullable();
            $table->text('expression')->nullable();        // formula mode: "{a} / {b} * 100"
            $table->json('variables')->nullable();         // formula mode: {a: {...simple config}, b: {...}}
            $table->string('format')->default('number');   // number|percent|currency
            $table->unsignedTinyInteger('decimals')->default(0);
            $table->string('subtitle')->nullable();
            $table->boolean('accent')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('metrics'); }
};