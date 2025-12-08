<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lesson_exercises', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lesson_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('lesson_sentence_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('type', 50);
            $table->string('skill', 50)->nullable();

            $table->text('question_prompt');
            $table->text('instructions')->nullable();
            $table->text('solution_explanation')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['lesson_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_exercises');
    }
};
