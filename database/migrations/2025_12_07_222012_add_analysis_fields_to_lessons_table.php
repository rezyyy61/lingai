<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->text('analysis_overview')->nullable()->after('original_text');
            $table->text('analysis_grammar')->nullable()->after('analysis_overview');
            $table->text('analysis_vocabulary')->nullable()->after('analysis_grammar');
            $table->text('analysis_study_tips')->nullable()->after('analysis_vocabulary');
            $table->json('analysis_meta')->nullable()->after('analysis_study_tips');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn([
                'analysis_overview',
                'analysis_grammar',
                'analysis_vocabulary',
                'analysis_study_tips',
                'analysis_meta',
            ]);
        });
    }
};
