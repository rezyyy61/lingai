<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lesson_sentences', function (Blueprint $table) {
            $table->string('tts_audio_path')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_sentences', function (Blueprint $table) {
            $table->dropColumn('tts_audio_path');
        });
    }
};
