<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\Lessons\LessonAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateLessonAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $lessonId;

    public ?string $customPrompt;

    public $tries = 2;

    public $backoff = 60;

    public function __construct(Lesson|int $lesson, ?string $customPrompt = null)
    {
        $this->lessonId = $lesson instanceof Lesson ? (int) $lesson->id : (int) $lesson;
        $this->customPrompt = $customPrompt;
    }

    public function handle(LessonAnalysisService $analysisService): void
    {
        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson) {
            return;
        }

        try {
            $analysis = $analysisService->generateAnalysis($lesson, $this->customPrompt);
        } catch (ConnectionException|RequestException|\Throwable $e) {
            report($e);
            return;
        }

        if (!is_array($analysis) || empty($analysis)) {
            return;
        }

        $oldMeta = (array) ($lesson->analysis_meta ?? []);
        $newMeta = (array) ($analysis['meta'] ?? []);

        $mergedMeta = array_merge($oldMeta, $newMeta);

        $lesson->update([
            'analysis_overview' => $analysis['overview'] ?? $lesson->analysis_overview,
            'analysis_grammar' => $analysis['grammar_points'] ?? $lesson->analysis_grammar,
            'analysis_vocabulary' => $analysis['vocabulary_focus'] ?? $lesson->analysis_vocabulary,
            'analysis_study_tips' => $analysis['study_tips'] ?? $lesson->analysis_study_tips,
            'analysis_meta' => $mergedMeta,
        ]);
    }

    public function failed(): void
    {
    }
}
