<?php

namespace App\Services\Flashcards;

use App\Models\Lesson;
use App\Models\LessonWord;
use App\Models\LessonWordReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetLessonFlashcardReviews
{
    public function handle(User $user, Lesson $lesson): void
    {
        abort_if($lesson->user_id !== $user->id, 403);

        $wordIds = LessonWord::query()
            ->where('lesson_id', $lesson->id)
            ->pluck('id');

        if ($wordIds->isEmpty()) {
            return;
        }

        $now = now();

        DB::transaction(function () use ($user, $wordIds, $now) {
            $existingIds = LessonWordReview::query()
                ->where('user_id', $user->id)
                ->whereIn('lesson_word_id', $wordIds)
                ->pluck('lesson_word_id')
                ->all();

            LessonWordReview::query()
                ->where('user_id', $user->id)
                ->whereIn('lesson_word_id', $wordIds)
                ->update([
                    'status' => 'new',
                    'next_review_at' => $now,
                    'last_reviewed_at' => null,
                    'review_count' => 0,
                    'success_count' => 0,
                    'failure_count' => 0,
                    'streak' => 0,
                    'interval_seconds' => 0,
                    'ease_factor' => (float) config('flashcards.review.initial_ease_factor', 2.3),
                    'updated_at' => $now,
                ]);

            $rows = [];

            foreach ($wordIds as $wordId) {
                if (in_array($wordId, $existingIds, true)) {
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
        });
    }
}
