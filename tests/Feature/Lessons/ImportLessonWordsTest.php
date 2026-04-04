<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportLessonWordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_words_from_a_json_style_payload(): void
    {
        [$user, $lesson] = $this->createOwnedLesson();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/words/import", [
            'replace_existing' => true,
            'words' => [
                [
                    'term' => 'in a hurry',
                    'meaning' => 'moving quickly because there is not much time',
                    'example_sentence' => 'I left home in a hurry.',
                    'translation' => 'با عجله',
                ],
                [
                    'term' => 'out of breath',
                    'meaning' => 'breathing hard after effort',
                    'example_sentence' => 'I was out of breath after running.',
                    'translation' => 'نفس‌نفس‌زنان',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJson([
                'status' => 'imported',
                'created' => 2,
                'skipped' => 0,
            ]);

        $this->assertDatabaseHas('lesson_words', [
            'lesson_id' => $lesson->id,
            'term' => 'in a hurry',
        ]);

        $this->assertDatabaseHas('lesson_words', [
            'lesson_id' => $lesson->id,
            'term' => 'out of breath',
        ]);
    }

    public function test_it_skips_duplicate_terms_when_appending_words(): void
    {
        [$user, $lesson] = $this->createOwnedLesson();

        $lesson->words()->create([
            'term' => 'in a hurry',
            'meaning' => 'existing',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/words/import", [
            'replace_existing' => false,
            'words' => [
                [
                    'term' => 'in a hurry',
                    'meaning' => 'new duplicate',
                ],
                [
                    'term' => 'miss the train',
                    'meaning' => 'to not catch the train in time',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJson([
                'created' => 1,
                'skipped' => 1,
            ]);

        $this->assertDatabaseCount('lesson_words', 2);
    }

    protected function createOwnedLesson(): array
    {
        $user = User::factory()->create();

        $workspace = Workspace::query()->create([
            'owner_id' => $user->id,
            'name' => 'English Workspace',
            'slug' => 'english-workspace-' . uniqid(),
            'target_language' => 'en',
            'support_language' => 'fa',
        ]);

        $lesson = Lesson::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Lesson',
            'resource_type' => 'text',
            'original_text' => 'Emma was in a hurry and almost out of breath.',
            'language' => 'en',
            'status' => 'draft',
        ]);

        return [$user, $lesson];
    }
}
