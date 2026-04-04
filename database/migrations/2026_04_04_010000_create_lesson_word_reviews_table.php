<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_word_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_word_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('new');
            $table->timestamp('next_review_at')->nullable()->index();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('streak')->default(0);
            $table->unsignedInteger('interval_seconds')->default(0);
            $table->decimal('ease_factor', 4, 2)->default(2.30);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_word_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_word_reviews');
    }
};
