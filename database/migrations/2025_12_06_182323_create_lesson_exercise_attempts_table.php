<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lesson_exercise_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lesson_exercise_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->text('user_answer')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->unsignedTinyInteger('score')->nullable();

            $table->text('feedback')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['lesson_exercise_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_exercise_attempts');
    }
};
