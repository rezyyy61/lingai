<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('resource_type', 30)->change();

            $table->string('audio_path')->nullable()->after('source_url');
            $table->string('audio_url')->nullable()->after('audio_path');

            $table->index('resource_type', 'lessons_resource_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex('lessons_resource_type_idx');
            $table->dropColumn(['audio_path', 'audio_url']);

            $table->enum('resource_type', ['video', 'text'])->change();
        });
    }
};
