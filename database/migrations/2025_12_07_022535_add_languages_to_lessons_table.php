<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('target_language', 10)
                ->default('en')
                ->after('original_text');

            $table->string('support_language', 10)
                ->default('en')
                ->after('target_language');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['target_language', 'support_language']);
        });
    }
};
