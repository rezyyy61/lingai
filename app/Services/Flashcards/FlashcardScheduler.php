<?php

namespace App\Services\Flashcards;

use App\Models\LessonWordReview;
use Carbon\CarbonInterface;

class FlashcardScheduler
{
    public function schedule(LessonWordReview $review, string $result, ?CarbonInterface $reviewedAt = null): array
    {
        $reviewedAt ??= now();

        return $result === 'know'
            ? $this->scheduleKnown($review, $reviewedAt)
            : $this->scheduleUnknown($review, $reviewedAt);
    }

    protected function scheduleKnown(LessonWordReview $review, CarbonInterface $reviewedAt): array
    {
        $successCount = (int) $review->success_count + 1;
        $failureCount = (int) $review->failure_count;
        $reviewCount = (int) $review->review_count + 1;
        $streak = (int) $review->streak + 1;

        $easeFactor = min(
            (float) config('flashcards.review.max_ease_factor', 3.0),
            round((float) $review->ease_factor + (float) config('flashcards.review.success_ease_step', 0.15), 2),
        );

        $previousInterval = max(0, (int) $review->interval_seconds);

        if ($successCount === 1) {
            $interval = (int) config('flashcards.review.first_success_interval_seconds', 43200);
        } elseif ($successCount === 2) {
            $interval = (int) config('flashcards.review.second_success_interval_seconds', 86400);
        } else {
            $growth = (float) config('flashcards.review.success_growth_multiplier', 1.6);
            $minimum = (int) config('flashcards.review.second_success_interval_seconds', 86400);
            $interval = (int) round(max($minimum, $previousInterval) * $easeFactor * $growth);
        }

        $masteredThreshold = (int) config('flashcards.review.mastered_interval_seconds', 1209600);

        return [
            'status' => $interval >= $masteredThreshold ? 'mastered' : 'reviewing',
            'next_review_at' => $reviewedAt->copy()->addSeconds($interval),
            'last_reviewed_at' => $reviewedAt,
            'review_count' => $reviewCount,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'streak' => $streak,
            'interval_seconds' => $interval,
            'ease_factor' => $easeFactor,
        ];
    }

    protected function scheduleUnknown(LessonWordReview $review, CarbonInterface $reviewedAt): array
    {
        $failureCount = (int) $review->failure_count + 1;
        $reviewCount = (int) $review->review_count + 1;

        $easeFactor = max(
            (float) config('flashcards.review.min_ease_factor', 1.3),
            round((float) $review->ease_factor - (float) config('flashcards.review.failure_ease_step', 0.2), 2),
        );

        $baseInterval = (int) config('flashcards.review.again_interval_seconds', 300);
        $step = (int) config('flashcards.review.failure_step_seconds', 300);
        $interval = min(
            (int) config('flashcards.review.max_failure_interval_seconds', 3600),
            $baseInterval + max(0, $failureCount - 1) * $step,
        );

        return [
            'status' => $review->review_count === 0 ? 'new' : 'learning',
            'next_review_at' => $reviewedAt->copy()->addSeconds($interval),
            'last_reviewed_at' => $reviewedAt,
            'review_count' => $reviewCount,
            'success_count' => (int) $review->success_count,
            'failure_count' => $failureCount,
            'streak' => 0,
            'interval_seconds' => $interval,
            'ease_factor' => $easeFactor,
        ];
    }
}
