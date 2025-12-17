<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\Lessons\AiLessonGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateAiLessonJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $lessonId) {}

    public function handle(AiLessonGeneratorService $gen): void
    {
        $lesson = Lesson::query()->findOrFail($this->lessonId);

        if ((string) $lesson->status !== 'generating') {
            return;
        }

        $meta = (array) ($lesson->analysis_meta ?? []);
        $g = (array) ($meta['ai_generation'] ?? []);

        $includeDialogue = filter_var(data_get($g, 'include_dialogue', true), FILTER_VALIDATE_BOOLEAN);
        $includeKeyPhrases = filter_var(data_get($g, 'include_key_phrases', true), FILTER_VALIDATE_BOOLEAN);
        $includeQuickQuestions = filter_var(data_get($g, 'include_quick_questions', true), FILTER_VALIDATE_BOOLEAN);

        Log::info('GenerateAiLessonJob flags', [
            'lesson_id' => $lesson->id,
            'include_dialogue' => $includeDialogue,
            'include_key_phrases' => $includeKeyPhrases,
            'include_quick_questions' => $includeQuickQuestions,
            'ai_generation_raw' => $g,
        ]);

        $pack = $gen->generate(
            topic: (string) data_get($g, 'topic', ''),
            goal: (string) data_get($g, 'goal', ''),
            level: (string) ($lesson->level ?? 'A2'),
            length: (string) data_get($g, 'length', 'medium'),
            targetLang: (string) $lesson->target_language,
            supportLang: (string) $lesson->support_language,
            keywords: (array) data_get($g, 'keywords', []),
            titleHint: (string) data_get($g, 'title_hint', ''),
            includeDialogue: $includeDialogue,
            includeKeyPhrases: $includeKeyPhrases,
            includeQuickQuestions: $includeQuickQuestions,
        );

        Log::info('GenerateAiLessonJob pack sizes', [
            'lesson_id' => $lesson->id,
            'dlg_count' => is_array($pack['dialogue'] ?? null) ? count($pack['dialogue']) : null,
            'kp_count' => is_array($pack['key_phrases'] ?? null) ? count($pack['key_phrases']) : null,
            'qq_count' => is_array($pack['quick_questions'] ?? null) ? count($pack['quick_questions']) : null,
        ]);

        $lessonText = trim((string) ($pack['lesson_text'] ?? ''));
        $title = trim((string) ($pack['title'] ?? ''));

        $lesson->update([
            'title' => $title !== '' ? $title : $lesson->title,
            'original_text' => $lessonText,
            'short_description' => $lessonText !== '' ? mb_substr($lessonText, 0, 180) : null,
            'tags' => array_values(array_unique(array_merge($lesson->tags ?? [], $pack['tags'] ?? []))),
            'status' => 'draft',
            'analysis_meta' => array_merge($meta, [
                'lesson_pack' => $pack,
            ]),
        ]);

        GenerateLessonAnalysisJob::dispatch($lesson->id);
    }

    public function failed(Throwable $e): void
    {
        $lesson = Lesson::query()->find($this->lessonId);
        if (!$lesson) return;

        $meta = (array) ($lesson->analysis_meta ?? []);

        $lesson->update([
            'status' => 'failed',
            'analysis_meta' => array_merge($meta, [
                'ai_generation_error' => mb_substr($e->getMessage(), 0, 900),
            ]),
        ]);
    }
}
