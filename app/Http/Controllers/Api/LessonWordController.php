<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ImportLessonWordsRequest;
use App\Http\Requests\StoreLessonWordRequest;
use App\Http\Requests\UpdateLessonWordRequest;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateLessonWordsJob;
use App\Models\Lesson;
use App\Models\LessonWord;
use App\Services\Lessons\LessonWordImportService;
use App\Support\LessonContentGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class LessonWordController extends Controller
{
    public function index(Request $request, Lesson $lesson)
    {
        $query = $lesson->words()->orderBy('term');

        if ($search = $request->string('q')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('term', 'like', "%{$search}%")
                    ->orWhere('meaning', 'like', "%{$search}%")
                    ->orWhere('example_sentence', 'like', "%{$search}%");
            });
        }

        if ($pos = $request->string('part_of_speech')->toString()) {
            $query->where('part_of_speech', $pos);
        }

        $words = $query->paginate(50);

        return response()->json($words);
    }

    public function generate(Request $request, Lesson $lesson)
    {
        if (trim((string) $lesson->original_text) === '') {
            return response()->json([
                'message' => 'Lesson has no original_text. Cannot generate words.',
            ], 422);
        }

        $data = $request->validate([
            'level' => ['nullable', 'string', 'max:20'],
            'domain' => ['nullable', 'string', 'max:50'],
            'min_items' => ['nullable', 'integer', 'min:1', 'max:100'],
            'max_items' => ['nullable', 'integer', 'min:1', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'inline_prompt' => ['nullable', 'string', 'max:2000'],
            'save_preset' => ['sometimes', 'boolean'],
            'replace_existing' => ['sometimes', 'boolean'],
        ]);

        $savePreset = $data['save_preset'] ?? false;
        $replaceExisting = $data['replace_existing'] ?? true;

        if ($savePreset) {
            $lesson->update([
                'word_prompt_level' => $data['level'] ?? $lesson->word_prompt_level,
                'word_prompt_domain' => $data['domain'] ?? $lesson->word_prompt_domain,
                'word_prompt_min_items' => $data['min_items'] ?? $lesson->word_prompt_min_items,
                'word_prompt_max_items' => $data['max_items'] ?? $lesson->word_prompt_max_items,
                'word_prompt_notes' => $data['notes'] ?? $lesson->word_prompt_notes,
            ]);
        } else {
            if (isset($data['level']) || isset($data['domain']) || isset($data['min_items']) || isset($data['max_items']) || isset($data['notes'])) {
                $lesson->word_prompt_level = $data['level'] ?? $lesson->word_prompt_level;
                $lesson->word_prompt_domain = $data['domain'] ?? $lesson->word_prompt_domain;
                $lesson->word_prompt_min_items = $data['min_items'] ?? $lesson->word_prompt_min_items;
                $lesson->word_prompt_max_items = $data['max_items'] ?? $lesson->word_prompt_max_items;
                $lesson->word_prompt_notes = $data['notes'] ?? $lesson->word_prompt_notes;
            }
        }

        $inlinePrompt = $data['inline_prompt'] ?? null;

        return DB::transaction(function () use ($lesson, $inlinePrompt, $replaceExisting) {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (LessonContentGeneration::currentStatus($lockedLesson, 'flashcards') === 'processing') {
                return response()->json([
                    'status' => 'processing',
                    'message' => 'Flashcard generation is already in progress.',
                ], 202);
            }

            LessonContentGeneration::markProcessing(
                $lockedLesson->id,
                'flashcards',
                'Generating flashcards for this lesson.'
            );

            GenerateLessonWordsJob::dispatch($lockedLesson->id, $inlinePrompt, $replaceExisting)->afterCommit();

            return response()->json([
                'status' => 'processing',
                'message' => 'Flashcard generation has been queued.',
            ], 202);
        });
    }

    public function import(ImportLessonWordsRequest $request, Lesson $lesson, LessonWordImportService $importService)
    {
        $result = $importService->import(
            lesson: $lesson,
            words: $request->validated('words', []),
            replaceExisting: (bool) $request->validated('replace_existing', true),
        );

        return response()->json([
            'status' => 'imported',
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'words' => $result['words'],
        ], 201);
    }

    public function store(StoreLessonWordRequest $request, Lesson $lesson)
    {
        $word = $lesson->words()->create($request->validated());

        return response()->json($word, 201);
    }

    public function show(Lesson $lesson, LessonWord $word)
    {
        if ($word->lesson_id !== $lesson->id) {
            abort(404);
        }

        return response()->json($word);
    }

    public function update(UpdateLessonWordRequest $request, Lesson $lesson, LessonWord $word)
    {
        if ($word->lesson_id !== $lesson->id) {
            abort(404);
        }

        $word->update($request->validated());

        return response()->json($word);
    }

    public function destroy(Lesson $lesson, LessonWord $word)
    {
        if ($word->lesson_id !== $lesson->id) {
            abort(404);
        }

        $word->delete();

        return response()->json([], 204);
    }
}
