<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Models\LessonGrammarPoint;
use App\Services\Lessons\LessonGrammarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateLessonGrammarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Lesson $lesson;

    public ?string $customPrompt;

    public bool $replaceExisting;

    public int $timeout = 60;

    public $tries = 1;

    public $backoff = 30;

    public function __construct(Lesson $lesson, ?string $customPrompt = null, bool $replaceExisting = true)
    {
        $this->lesson = $lesson;
        $this->customPrompt = $customPrompt;
        $this->replaceExisting = $replaceExisting;
    }

    public function handle(LessonGrammarService $grammarService): void
    {
        $lesson = $this->lesson->fresh();

        if (! $lesson || ! $lesson->original_text || trim((string) $lesson->original_text) === '') {
            return;
        }

        try {
            $result = $grammarService->generateGrammar($lesson, $this->customPrompt);
        } catch (Throwable $e) {
            Log::error('GenerateLessonGrammarJob: service exception', [
                'lesson_id' => $lesson->id ?? null,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);
            return;
        }

        $grammarPoints = $result['grammar_points'] ?? [];

        if (! is_array($grammarPoints) || empty($grammarPoints)) {
            return;
        }

        try {
            DB::transaction(function () use ($lesson, $grammarPoints) {
                if ($this->replaceExisting) {
                    LessonGrammarPoint::where('lesson_id', $lesson->id)->delete();
                }

                foreach ($grammarPoints as $index => $point) {
                    if (! is_array($point)) {
                        continue;
                    }

                    $key = isset($point['id']) && ! is_array($point['id']) ? (string) $point['id'] : null;

                    $title = isset($point['title']) && ! is_array($point['title'])
                        ? (string) $point['title']
                        : 'Grammar point ' . ($index + 1);

                    $level = isset($point['level']) && ! is_array($point['level']) ? $point['level'] : null;

                    $description = isset($point['description']) && ! is_array($point['description'])
                        ? (string) $point['description']
                        : null;

                    $pattern = isset($point['pattern']) && ! is_array($point['pattern'])
                        ? (string) $point['pattern']
                        : null;

                    $examples = isset($point['examples']) && is_array($point['examples']) ? $point['examples'] : [];

                    $meta = isset($point['meta']) && is_array($point['meta']) ? $point['meta'] : null;

                    LessonGrammarPoint::create([
                        'lesson_id' => $lesson->id,
                        'key' => $key,
                        'title' => $title,
                        'level' => is_string($level) && trim($level) !== '' ? trim($level) : null,
                        'description' => $description,
                        'pattern' => $pattern,
                        'examples' => json_encode($examples, JSON_UNESCAPED_UNICODE),
                        'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                    ]);
                }
            });
        } catch (Throwable $e) {
            Log::error('GenerateLessonGrammarJob: db failure', [
                'lesson_id' => $lesson->id ?? null,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('GenerateLessonGrammarJob: failed', [
            'lesson_id' => $this->lesson->id ?? null,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
}
