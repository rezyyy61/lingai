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

        $pack = $gen->generateDialogueOnly(
            topic: (string) data_get($g, 'topic', ''),
            goal: (string) data_get($g, 'goal', ''),
            level: (string) ($lesson->level ?? 'A2'),
            length: (string) data_get($g, 'length', 'medium'),
            targetLang: (string) $lesson->target_language,
            supportLang: (string) $lesson->support_language,
            keywords: (array) data_get($g, 'keywords', []),
            titleHint: (string) data_get($g, 'title_hint', ''),
        );

        $dialogueText = $this->renderDialogueToOriginalText((array) ($pack['dialogue'] ?? []));

        Log::info('GenerateAiLessonJob dialogue-only pack', [
            'lesson_id' => $lesson->id,
            'dlg_count' => is_array($pack['dialogue'] ?? null) ? count($pack['dialogue']) : null,
            'title_len' => mb_strlen((string) ($pack['title'] ?? '')),
            'original_text_len' => mb_strlen($dialogueText),
        ]);

        $title = trim((string) ($pack['title'] ?? ''));

        $lesson->update([
            'title' => $title !== '' ? $title : $lesson->title,
            'original_text' => $dialogueText,
            'short_description' => $dialogueText !== '' ? mb_substr($dialogueText, 0, 180) : null,
            'tags' => array_values(array_unique(array_merge($lesson->tags ?? [], $pack['tags'] ?? []))),
            'status' => 'draft',
            'analysis_meta' => array_merge($meta, [
                'lesson_pack' => $pack,
            ]),
        ]);

        GenerateLessonAnalysisJob::dispatch($lesson);
    }

    protected function renderDialogueToOriginalText(array $dialogue): string
    {
        $lines = [];

        foreach ($dialogue as $row) {
            if (!is_array($row)) continue;
            $sp = trim((string) ($row['speaker'] ?? ''));
            $tx = trim((string) ($row['text'] ?? ''));
            if ($sp === '' || $tx === '') continue;

            $lines[] = $sp . ': ' . $tx;
        }

        return trim(implode("\n>>\n", $lines));
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
