<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateLessonSentencesJob;
use App\Models\Lesson;
use App\Models\LessonSentence;
use Illuminate\Http\Request;

class LessonSentenceController extends Controller
{
    public function index(Request $request, Lesson $lesson)
    {
        $query = $lesson->sentences()->orderBy('order_index');

        if ($search = $request->string('q')->toString()) {
            $query->where('text', 'like', "%{$search}%");
        }

        if ($source = $request->string('source')->toString()) {
            $query->where('source', $source);
        }

        $sentences = $query->get();

        return response()->json($sentences);
    }

    public function generate(Request $request, Lesson $lesson)
    {
        if (! $lesson->original_text) {
            return response()->json([
                'message' => 'Lesson has no original_text. Cannot generate sentences.',
            ], 422);
        }

        $data = $request->validate([
            'custom_prompt' => ['nullable', 'string', 'max:2000'],
            'replace_existing' => ['sometimes', 'boolean'],
        ]);

        $customPrompt = $data['custom_prompt'] ?? null;
        $replaceExisting = $data['replace_existing'] ?? true;

        GenerateLessonSentencesJob::dispatch($lesson, $customPrompt, $replaceExisting);

        return response()->json([
            'status' => 'queued',
            'message' => 'Sentence generation job has been queued for this lesson.',
        ], 202);
    }

    public function show(Lesson $lesson, LessonSentence $sentence)
    {
        if ($sentence->lesson_id !== $lesson->id) {
            abort(404);
        }

        return response()->json($sentence);
    }
}
