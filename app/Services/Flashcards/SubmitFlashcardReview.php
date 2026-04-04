<?php

namespace App\Services\Flashcards;

use App\Models\LessonWord;
use App\Models\LessonWordReview;
use App\Models\User;

class SubmitFlashcardReview
{
    public function __construct(
        protected FlashcardScheduler $scheduler,
        protected GetDueFlashcards $dueFlashcards,
    ) {}

    public function handle(User $user, LessonWord $word, string $result): LessonWordReview
    {
        abort_if($word->lesson?->user_id !== $user->id, 403);

        $review = LessonWordReview::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'lesson_word_id' => $word->id,
            ],
            [
                'status' => 'new',
                'next_review_at' => now(),
                'interval_seconds' => 0,
                'ease_factor' => (float) config('flashcards.review.initial_ease_factor', 2.3),
            ],
        );

        $review->fill($this->scheduler->schedule($review, $result));
        $review->save();

        return $review->fresh();
    }
}
