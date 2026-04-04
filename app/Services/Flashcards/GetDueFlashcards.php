<?php

namespace App\Services\Flashcards;

use App\Models\Lesson;
use App\Models\LessonWord;
use App\Models\LessonWordReview;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class GetDueFlashcards
{
    public function forLesson(User $user, Lesson $lesson, int $limit = 20): array
    {
        $this->ensureAccessible($user, $lesson);
        $this->ensureReviewRows($user, $lesson);

        $limit = max(1, min(100, $limit));
        $now = now();

        $dueQuery = LessonWord::query()
            ->select('lesson_words.*')
            ->join('lesson_word_reviews', 'lesson_word_reviews.lesson_word_id', '=', 'lesson_words.id')
            ->where('lesson_words.lesson_id', $lesson->id)
            ->where('lesson_word_reviews.user_id', $user->id)
            ->where(function ($query) use ($now) {
                $query
                    ->whereNull('lesson_word_reviews.next_review_at')
                    ->orWhere('lesson_word_reviews.next_review_at', '<=', $now);
            })
            ->with(['reviews' => function ($query) use ($user) {
                $query->where('user_id', $user->id);
            }])
            ->orderByRaw("
                case lesson_word_reviews.status
                    when 'learning' then 0
                    when 'new' then 1
                    when 'reviewing' then 2
                    when 'mastered' then 3
                    else 4
                end
            ")
            ->orderBy('lesson_word_reviews.next_review_at')
            ->limit($limit);

        $cards = $dueQuery->get();

        $dueCount = LessonWordReview::query()
            ->where('user_id', $user->id)
            ->whereHas('lessonWord', fn ($query) => $query->where('lesson_id', $lesson->id))
            ->where(function ($query) use ($now) {
                $query
                    ->whereNull('next_review_at')
                    ->orWhere('next_review_at', '<=', $now);
            })
            ->count();

        return [
            'cards' => $cards,
            'due_count' => $dueCount,
        ];
    }

    public function remainingDueCount(User $user, LessonWord $word): int
    {
        $now = now();

        return LessonWordReview::query()
            ->where('user_id', $user->id)
            ->whereHas('lessonWord', fn ($query) => $query->where('lesson_id', $word->lesson_id))
            ->where(function ($query) use ($now) {
                $query
                    ->whereNull('next_review_at')
                    ->orWhere('next_review_at', '<=', $now);
            })
            ->count();
    }

    protected function ensureAccessible(User $user, Lesson $lesson): void
    {
        abort_if($lesson->user_id !== $user->id, 403);
    }

    protected function ensureReviewRows(User $user, Lesson $lesson): void
    {
        $wordIds = LessonWord::query()
            ->where('lesson_id', $lesson->id)
            ->pluck('id');

        if ($wordIds->isEmpty()) {
            return;
        }

        $existing = LessonWordReview::query()
            ->where('user_id', $user->id)
            ->whereIn('lesson_word_id', $wordIds)
            ->pluck('lesson_word_id')
            ->all();

        $now = now();
        $rows = [];

        foreach ($wordIds as $wordId) {
            if (in_array($wordId, $existing, true)) {
                continue;
            }

            $rows[] = [
                'user_id' => $user->id,
                'lesson_word_id' => $wordId,
                'status' => 'new',
                'next_review_at' => $now,
                'last_reviewed_at' => null,
                'review_count' => 0,
                'success_count' => 0,
                'failure_count' => 0,
                'streak' => 0,
                'interval_seconds' => 0,
                'ease_factor' => (float) config('flashcards.review.initial_ease_factor', 2.3),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            LessonWordReview::query()->insert($rows);
        }
    }
}
