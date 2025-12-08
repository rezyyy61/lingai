<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lesson_sentence_word', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lesson_sentence_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('lesson_word_id')
                ->constrained('lesson_words')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['lesson_sentence_id', 'lesson_word_id'], 'sentence_word_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sentence_word');
    }
};
