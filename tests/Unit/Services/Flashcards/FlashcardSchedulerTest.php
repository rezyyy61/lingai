<?php

namespace Tests\Unit\Services\Flashcards;

use App\Models\LessonWordReview;
use App\Services\Flashcards\FlashcardScheduler;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class FlashcardSchedulerTest extends TestCase
{
    public function test_it_schedules_known_cards_with_a_longer_interval(): void
    {
        $scheduler = new FlashcardScheduler();
        $reviewedAt = CarbonImmutable::parse('2026-04-04 10:00:00');

        $review = new LessonWordReview([
            'review_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'streak' => 0,
            'interval_seconds' => 0,
            'ease_factor' => 2.3,
        ]);

        $payload = $scheduler->schedule($review, 'know', $reviewedAt);

        $this->assertSame('reviewing', $payload['status']);
        $this->assertSame(1, $payload['review_count']);
        $this->assertSame(1, $payload['success_count']);
        $this->assertSame(0, $payload['failure_count']);
        $this->assertSame(1, $payload['streak']);
        $this->assertSame(43200, $payload['interval_seconds']);
        $this->assertTrue($payload['next_review_at']->equalTo($reviewedAt->addSeconds(43200)));
    }

    public function test_it_schedules_unknown_cards_much_sooner(): void
    {
        $scheduler = new FlashcardScheduler();
        $reviewedAt = CarbonImmutable::parse('2026-04-04 10:00:00');

        $review = new LessonWordReview([
            'review_count' => 2,
            'success_count' => 1,
            'failure_count' => 1,
            'streak' => 1,
            'interval_seconds' => 86400,
            'ease_factor' => 2.45,
        ]);

        $payload = $scheduler->schedule($review, 'dont_know', $reviewedAt);

        $this->assertSame('learning', $payload['status']);
        $this->assertSame(3, $payload['review_count']);
        $this->assertSame(1, $payload['success_count']);
        $this->assertSame(2, $payload['failure_count']);
        $this->assertSame(0, $payload['streak']);
        $this->assertSame(600, $payload['interval_seconds']);
        $this->assertTrue($payload['next_review_at']->equalTo($reviewedAt->addSeconds(600)));
        $this->assertLessThan(2.45, $payload['ease_factor']);
    }

    public function test_repeated_successes_expand_the_interval_progressively(): void
    {
        $scheduler = new FlashcardScheduler();
        $reviewedAt = CarbonImmutable::parse('2026-04-04 10:00:00');

        $review = new LessonWordReview([
            'review_count' => 3,
            'success_count' => 2,
            'failure_count' => 1,
            'streak' => 2,
            'interval_seconds' => 86400,
            'ease_factor' => 2.45,
        ]);

        $payload = $scheduler->schedule($review, 'know', $reviewedAt);

        $this->assertSame('reviewing', $payload['status']);
        $this->assertGreaterThan(86400, $payload['interval_seconds']);
        $this->assertSame(3, $payload['success_count']);
        $this->assertSame(3, $payload['streak']);
    }
}
