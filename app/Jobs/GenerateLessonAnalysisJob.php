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

    public Lesson $lesson;

    public ?string $customPrompt;

    public $tries = 2;

    public $backoff = 60;

    public function __construct(Lesson $lesson, ?string $customPrompt = null)
    {
        $this->lesson = $lesson;
        $this->customPrompt = $customPrompt;
    }

    public function handle(LessonAnalysisService $analysisService): void
    {
        $lesson = $this->lesson->fresh();

        if (! $lesson) {
            return;
        }

        try {
            $analysis = $analysisService->generateAnalysis($lesson, $this->customPrompt);
        } catch (ConnectionException|RequestException|\Throwable $e) {
            report($e);
            return;
        }

        if (empty($analysis)) {
            return;
        }

        $lesson->update([
            'analysis_overview' => $analysis['overview'] ?? $lesson->analysis_overview,
            'analysis_grammar' => $analysis['grammar_points'] ?? $lesson->analysis_grammar,
            'analysis_vocabulary' => $analysis['vocabulary_focus'] ?? $lesson->analysis_vocabulary,
            'analysis_study_tips' => $analysis['study_tips'] ?? $lesson->analysis_study_tips,
            'analysis_meta' => $analysis['meta'] ?? $lesson->analysis_meta,
        ]);
    }

    public function failed(): void
    {
    }
}
