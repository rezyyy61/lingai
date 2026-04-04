<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\LessonWord;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlashcardReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_due_cards_for_a_lesson(): void
    {
        [$user, $lesson] = $this->createOwnedLesson();

        LessonWord::query()->create([
            'lesson_id' => $lesson->id,
            'term' => 'in a hurry',
            'meaning' => 'moving quickly because there is not much time',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/lessons/{$lesson->id}/words/review");

        $response
            ->assertOk()
            ->assertJsonPath('meta.due_count', 1)
            ->assertJsonPath('data.0.term', 'in a hurry')
            ->assertJsonPath('data.0.review.status', 'new');
    }

    public function test_it_submits_a_review_and_updates_the_schedule(): void
    {
        [$user, $lesson] = $this->createOwnedLesson();

        $word = LessonWord::query()->create([
            'lesson_id' => $lesson->id,
            'term' => 'out of breath',
            'meaning' => 'breathing hard after effort',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/lessons/{$lesson->id}/words/review")->assertOk();

        $response = $this->postJson('/api/lesson-words/review', [
            'lesson_word_id' => $word->id,
            'result' => 'know',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'reviewed')
            ->assertJsonPath('review.success_count', 1)
            ->assertJsonPath('review.review_count', 1)
            ->assertJsonPath('review.status', 'reviewing');

        $this->assertDatabaseHas('lesson_word_reviews', [
            'user_id' => $user->id,
            'lesson_word_id' => $word->id,
            'success_count' => 1,
            'review_count' => 1,
        ]);
    }

    protected function createOwnedLesson(): array
    {
        $user = User::factory()->create();

        $workspace = Workspace::query()->create([
            'owner_id' => $user->id,
            'name' => 'English Workspace',
            'slug' => 'review-workspace-' . uniqid(),
            'target_language' => 'en',
            'support_language' => 'fa',
        ]);

        $lesson = Lesson::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Lesson',
            'resource_type' => 'text',
            'original_text' => 'Emma was in a hurry.',
            'language' => 'en',
            'status' => 'draft',
        ]);

        return [$user, $lesson];
    }
}
