<?php

namespace Tests\Unit\Services\Lessons;

use App\Models\Lesson;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Lessons\GenerateLessonAudioScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GenerateLessonAudioScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_the_provider_response_into_existing_lesson_fields(): void
    {
        config()->set('lesson_generation.azure_openai.endpoint', 'https://example-resource.openai.azure.com');
        config()->set('lesson_generation.azure_openai.api_key', 'test-key');
        config()->set('lesson_generation.azure_openai.api_version', '2025-01-01-preview');
        config()->set('lesson_generation.azure_openai.deployment', 'lesson-script');
        config()->set('lesson_generation.azure_openai.use_v1', true);

        Http::fake([
            'https://example-resource.openai.azure.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'source_language' => 'French',
                                'output_language' => 'French',
                                'title' => 'La routine du matin',
                                'level' => 'A2',
                                'summary' => 'Une courte lecon orale sur les habitudes du matin.',
                                'key_vocabulary' => [
                                    [
                                        'term' => 'se reveiller',
                                        'meaning' => 'to wake up',
                                        'example' => 'Je me reveille a sept heures.',
                                    ],
                                ],
                                'key_expressions' => [
                                    [
                                        'expression' => 'prendre le petit-dejeuner',
                                        'meaning' => 'to have breakfast',
                                        'example' => 'Je prends le petit-dejeuner avec du cafe.',
                                    ],
                                ],
                                'comprehension_questions' => [
                                    [
                                        'question' => 'A quelle heure se reveille la personne ?',
                                        'answer' => 'Elle se reveille a sept heures.',
                                    ],
                                ],
                                'spoken_segments' => [
                                    [
                                        'type' => 'intro',
                                        'speaker' => 'coach',
                                        'style' => 'friendly',
                                        'pause_ms' => 700,
                                        'text' => 'Bonjour et bienvenue.',
                                    ],
                                    [
                                        'type' => 'teaching',
                                        'speaker' => 'helper',
                                        'style' => 'gentle',
                                        'pause_ms' => 500,
                                        'text' => 'Aujourd\'hui, nous allons etudier le vocabulaire utile pour parler de la routine du matin.',
                                    ],
                                ],
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $lesson = $this->createLesson([
            'title' => 'Lesson',
            'analysis_overview' => 'Old overview',
            'analysis_vocabulary' => 'Old vocabulary',
            'analysis_study_tips' => 'Old tips',
            'analysis_meta' => ['existing' => 'value'],
            'status' => 'processing',
        ]);

        $result = app(GenerateLessonAudioScript::class)->handle($lesson);

        $this->assertSame('ready', $result->status);
        $this->assertSame('La routine du matin', $result->title);
        $this->assertSame('fr', $result->language);
        $this->assertSame('A2', $result->level);
        $this->assertSame('Une courte lecon orale sur les habitudes du matin.', $result->analysis_overview);
        $this->assertStringContainsString('se reveiller: to wake up', (string) $result->analysis_vocabulary);
        $this->assertStringContainsString('Key expressions:', (string) $result->analysis_study_tips);
        $this->assertStringContainsString('Comprehension check:', (string) $result->analysis_study_tips);
        $this->assertSame('value', data_get($result->analysis_meta, 'existing'));
        $this->assertCount(2, data_get($result->analysis_meta, 'audio_script.spoken_segments', []));
        $this->assertSame('coach', data_get($result->analysis_meta, 'audio_script.spoken_segments.0.speaker'));
        $this->assertSame('Bonjour et bienvenue.', data_get($result->analysis_meta, 'audio_script.spoken_segments.0.text'));
        $this->assertSame('fr', data_get($result->analysis_meta, 'audio_script.source_language_code'));
    }

    public function test_it_does_not_overwrite_previous_generated_data_when_spoken_segments_are_invalid(): void
    {
        config()->set('lesson_generation.azure_openai.endpoint', 'https://example-resource.openai.azure.com');
        config()->set('lesson_generation.azure_openai.api_key', 'test-key');
        config()->set('lesson_generation.azure_openai.api_version', '2025-01-01-preview');
        config()->set('lesson_generation.azure_openai.deployment', 'lesson-script');
        config()->set('lesson_generation.azure_openai.use_v1', true);

        Http::fake([
            'https://example-resource.openai.azure.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'source_language' => 'French',
                                'output_language' => 'French',
                                'title' => 'La routine du matin',
                                'level' => 'A2',
                                'summary' => 'Une courte lecon orale sur les habitudes du matin.',
                                'key_vocabulary' => [],
                                'key_expressions' => [],
                                'comprehension_questions' => [],
                                'spoken_segments' => [
                                    [
                                        'type' => 'intro',
                                        'speaker' => 'coach',
                                        'style' => 'friendly',
                                        'pause_ms' => 'oops',
                                        'text' => '',
                                    ],
                                ],
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $lesson = $this->createLesson([
            'analysis_overview' => 'Old overview',
            'analysis_meta' => [
                'audio_script' => [
                    'spoken_segments' => [
                        [
                            'type' => 'intro',
                            'speaker' => 'coach',
                            'style' => 'friendly',
                            'pause_ms' => 300,
                            'text' => 'Existing segment',
                        ],
                    ],
                ],
            ],
            'status' => 'processing',
        ]);

        $this->expectException(RuntimeException::class);

        try {
            app(GenerateLessonAudioScript::class)->handle($lesson);
        } finally {
            $fresh = $lesson->fresh();
            $this->assertSame('Old overview', $fresh->analysis_overview);
            $this->assertSame('Existing segment', data_get($fresh->analysis_meta, 'audio_script.spoken_segments.0.text'));
        }
    }

    protected function createLesson(array $lessonOverrides = []): Lesson
    {
        $user = User::factory()->create();

        $workspace = Workspace::query()->create([
            'owner_id' => $user->id,
            'name' => 'Workspace',
            'slug' => 'workspace-' . uniqid(),
            'target_language' => 'fr',
            'support_language' => 'en',
        ]);

        return Lesson::query()->create(array_merge([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Lesson',
            'resource_type' => 'text',
            'source_url' => null,
            'original_text' => 'Je me reveille a sept heures et je prends le petit-dejeuner.',
            'language' => 'fr',
            'level' => null,
            'short_description' => 'Routine du matin',
            'status' => 'draft',
        ], $lessonOverrides));
    }
}
