<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitFlashcardReviewRequest;
use App\Models\Lesson;
use App\Models\LessonWord;
use App\Services\Flashcards\GetDueFlashcards;
use App\Services\Flashcards\ResetLessonFlashcardReviews;
use App\Services\Flashcards\SubmitFlashcardReview;
use Illuminate\Http\Request;

class FlashcardReviewController extends Controller
{
    public function index(Request $request, Lesson $lesson, GetDueFlashcards $dueFlashcards)
    {
        $user = $request->user();
        $limit = $request->integer('limit', (int) config('flashcards.review.session_limit', 20));

        $result = $dueFlashcards->forLesson($user, $lesson, $limit);

        return response()->json([
            'data' => collect($result['cards'])->map(fn (LessonWord $word) => $this->serializeWord($word))->values(),
            'meta' => [
                'due_count' => $result['due_count'],
                'lesson_id' => $lesson->id,
                'limit' => $limit,
            ],
        ]);
    }

    public function store(SubmitFlashcardReviewRequest $request, SubmitFlashcardReview $submitReview, GetDueFlashcards $dueFlashcards)
    {
        $user = $request->user();
        $word = LessonWord::query()->with('lesson')->findOrFail($request->integer('lesson_word_id'));
        $review = $submitReview->handle($user, $word, $request->validated('result'));

        return response()->json([
            'status' => 'reviewed',
            'card' => $this->serializeWord($word->fresh(['reviews' => fn ($query) => $query->where('user_id', $user->id)])),
            'review' => $this->serializeReview($review),
            'remaining_due_count' => $dueFlashcards->remainingDueCount($user, $word),
        ]);
    }

    public function reset(Request $request, Lesson $lesson, ResetLessonFlashcardReviews $resetReviews, GetDueFlashcards $dueFlashcards)
    {
        $user = $request->user();

        $resetReviews->handle($user, $lesson);
        $result = $dueFlashcards->forLesson($user, $lesson, $request->integer('limit', (int) config('flashcards.review.session_limit', 20)));

        return response()->json([
            'status' => 'reset',
            'data' => collect($result['cards'])->map(fn (LessonWord $word) => $this->serializeWord($word))->values(),
            'meta' => [
                'due_count' => $result['due_count'],
                'lesson_id' => $lesson->id,
            ],
        ]);
    }

    protected function serializeWord(LessonWord $word): array
    {
        return [
            'id' => $word->id,
            'lesson_id' => $word->lesson_id,
            'term' => $word->term,
            'meaning' => $word->meaning,
            'translation' => $word->translation,
            'example_sentence' => $word->example_sentence,
            'phonetic' => $word->phonetic,
            'part_of_speech' => $word->part_of_speech,
            'review' => $this->serializeReview($word->reviews->first()),
        ];
    }

    protected function serializeReview($review): ?array
    {
        if (! $review) {
            return null;
        }

        return [
            'status' => $review->status,
            'next_review_at' => optional($review->next_review_at)?->toIso8601String(),
            'last_reviewed_at' => optional($review->last_reviewed_at)?->toIso8601String(),
            'review_count' => $review->review_count,
            'success_count' => $review->success_count,
            'failure_count' => $review->failure_count,
            'streak' => $review->streak,
            'interval_seconds' => $review->interval_seconds,
            'ease_factor' => $review->ease_factor,
        ];
    }
}
