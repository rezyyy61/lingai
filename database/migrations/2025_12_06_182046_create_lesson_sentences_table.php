<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lesson_sentences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('order_index');
            $table->text('text');

            $table->enum('source', ['original', 'generated'])
                ->default('original');

            $table->unsignedInteger('start_time')->nullable();
            $table->unsignedInteger('end_time')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['lesson_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sentences');
    }
};
