<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lesson_sentence_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lesson_sentence_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('audio_path');

            $table->unsignedTinyInteger('score_overall')->nullable();
            $table->unsignedTinyInteger('score_accuracy')->nullable();
            $table->unsignedTinyInteger('score_fluency')->nullable();
            $table->unsignedTinyInteger('score_completeness')->nullable();

            $table->json('raw_response')->nullable();

            $table->timestamps();

            $table->index(['lesson_sentence_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sentence_attempts');
    }
};
