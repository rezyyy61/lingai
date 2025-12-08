<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lesson_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('term');
            $table->string('lemma')->nullable();
            $table->string('phonetic')->nullable();
            $table->string('part_of_speech', 50)->nullable();

            $table->text('meaning')->nullable();
            $table->text('example_sentence')->nullable();
            $table->string('translation')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['lesson_id', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_words');
    }
};
