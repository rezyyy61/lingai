<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete(); // creator/owner if you want

            $table->string('title');
            $table->enum('resource_type', ['video', 'text']);
            $table->string('source_url')->nullable();
            $table->text('original_text');
            $table->string('language', 10)->default('en');
            $table->string('level', 10)->nullable();

            $table->string('short_description')->nullable();
            $table->json('tags')->nullable();

            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
