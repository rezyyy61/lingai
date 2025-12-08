<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('word_prompt_level', 20)->nullable()->after('support_language');
            $table->string('word_prompt_domain', 50)->nullable()->after('word_prompt_level');
            $table->unsignedSmallInteger('word_prompt_min_items')->nullable()->after('word_prompt_domain');
            $table->unsignedSmallInteger('word_prompt_max_items')->nullable()->after('word_prompt_min_items');
            $table->text('word_prompt_notes')->nullable()->after('word_prompt_max_items');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn([
                'word_prompt_level',
                'word_prompt_domain',
                'word_prompt_min_items',
                'word_prompt_max_items',
                'word_prompt_notes',
            ]);
        });
    }
};
