<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lesson_exercise_options', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lesson_exercise_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('order_index')->default(0);
            $table->string('label', 5)->nullable();
            $table->text('text');
            $table->boolean('is_correct')->default(false);
            $table->text('explanation')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_exercise_options');
    }
};
