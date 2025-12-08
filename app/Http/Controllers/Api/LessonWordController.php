<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateLessonWordsJob;
use App\Models\Lesson;
use App\Models\LessonWord;
use App\Services\Lessons\LessonWordPromptBuilder;
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

    public function generate(Request $request, Lesson $lesson, LessonWordPromptBuilder $promptBuilder)
    {
        if (! $lesson->original_text) {
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

        $finalPrompt = $promptBuilder->build($lesson, $inlinePrompt);

        GenerateLessonWordsJob::dispatch($lesson, $finalPrompt, $replaceExisting);

        return response()->json([
            'status' => 'queued',
            'message' => 'Word generation job has been queued for this lesson.',
        ], 202);
    }

    public function store(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'term' => ['required', 'string', 'max:255'],
            'lemma' => ['nullable', 'string', 'max:255'],
            'phonetic' => ['nullable', 'string', 'max:255'],
            'part_of_speech' => ['nullable', 'string', 'max:50'],
            'meaning' => ['nullable', 'string'],
            'example_sentence' => ['nullable', 'string'],
            'translation' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ]);

        $word = $lesson->words()->create($data);

        return response()->json($word, 201);
    }

    public function show(Lesson $lesson, LessonWord $word)
    {
        if ($word->lesson_id !== $lesson->id) {
            abort(404);
        }

        return response()->json($word);
    }

    public function update(Request $request, Lesson $lesson, LessonWord $word)
    {
        if ($word->lesson_id !== $lesson->id) {
            abort(404);
        }

        $data = $request->validate([
            'term' => ['sometimes', 'string', 'max:255'],
            'lemma' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phonetic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'part_of_speech' => ['sometimes', 'nullable', 'string', 'max:50'],
            'meaning' => ['sometimes', 'nullable', 'string'],
            'example_sentence' => ['sometimes', 'nullable', 'string'],
            'translation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ]);

        $word->update($data);

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
