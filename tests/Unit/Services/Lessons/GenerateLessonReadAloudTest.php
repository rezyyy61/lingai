<?php

namespace Tests\Unit\Services\Lessons;

use App\Models\Lesson;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audio\LessonAudioChunkMerger;
use App\Services\Lessons\GenerateLessonReadAloud;
use App\Services\Speech\Contracts\TextToSpeechInterface;
use App\Services\Speech\TextToSpeechManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GenerateLessonReadAloudTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_and_persists_read_aloud_audio_from_original_text(): void
    {
        config()->set('lesson_generation.read_aloud.disk', 'public');
        config()->set('lesson_generation.read_aloud.directory', 'lessons');
        config()->set('lesson_generation.read_aloud.format', 'mp3');
        config()->set('lesson_generation.read_aloud.locale_fallback', 'en-US');
        config()->set('lesson_generation.read_aloud.voice_map', ['en-US' => 'en-US-JennyNeural']);
        config()->set('lesson_generation.read_aloud.rate', '-4%');
        config()->set('lesson_generation.read_aloud.style_map', ['en-US' => 'chat']);
        config()->set('lesson_generation.read_aloud.chunk_break_ms', 0);
        config()->set('lesson_generation.read_aloud.paragraph_break_ms', 520);
        config()->set('lesson_generation.read_aloud.sentence_break_ms', 180);
        config()->set('lesson_generation.read_aloud.chunk.max_chars', 80);
        config()->set('lesson_generation.read_aloud.generation_version', 'read-aloud-quality-v2');

        Storage::fake('public');

        $provider = Mockery::mock(TextToSpeechInterface::class);
        $provider->shouldReceive('synthesizeReadAloudText')
            ->twice()
            ->andReturn(
                [
                    'binary' => 'chunk-audio-1',
                    'voice' => 'en-US-JennyNeural',
                    'format' => 'wav',
                    'locale' => 'en-US',
                    'style' => 'chat',
                    'provider' => 'azure',
                    'output_format' => 'riff-24khz-16bit-mono-pcm',
                    'sync_precision' => 'word',
                    'alignment_provider' => 'elevenlabs_with_timestamps',
                    'model_id' => 'eleven_multilingual_v2',
                    'read_aloud_speed' => 0.75,
                    'timestamp_mode' => 'elevenlabs_with_timestamps',
                    'word_timestamps' => [
                        ['text' => 'Emma', 'start' => 0.0, 'end' => 0.4, 'start_char' => 0, 'end_char' => 4, 'chunk_index' => 0],
                        ['text' => 'looked', 'start' => 0.45, 'end' => 0.82, 'start_char' => 5, 'end_char' => 11, 'chunk_index' => 0],
                    ],
                    'alignments' => [
                        [
                            'index' => 0,
                            'text' => 'Emma looked at her watch.',
                            'spoken_text' => 'Emma looked at her watch.',
                            'duration' => 1.2,
                            'pause_ms' => 0,
                            'alignment' => ['characters' => ['E']],
                            'normalized_alignment' => ['characters' => ['E']],
                            'word_timestamps' => [
                                ['text' => 'Emma', 'start' => 0.0, 'end' => 0.4, 'start_char' => 0, 'end_char' => 4, 'chunk_index' => 0],
                            ],
                        ],
                    ],
                ],
                [
                    'binary' => 'chunk-audio-2',
                    'voice' => 'en-US-JennyNeural',
                    'format' => 'wav',
                    'locale' => 'en-US',
                    'style' => 'chat',
                    'provider' => 'azure',
                    'output_format' => 'riff-24khz-16bit-mono-pcm',
                    'sync_precision' => 'word',
                    'alignment_provider' => 'elevenlabs_with_timestamps',
                    'model_id' => 'eleven_multilingual_v2',
                    'read_aloud_speed' => 0.75,
                    'timestamp_mode' => 'elevenlabs_with_timestamps',
                    'word_timestamps' => [
                        ['text' => 'She', 'start' => 0.0, 'end' => 0.25, 'start_char' => 0, 'end_char' => 3, 'chunk_index' => 0],
                    ],
                    'alignments' => [
                        [
                            'index' => 0,
                            'text' => 'She started walking faster because the train was about to leave.',
                            'spoken_text' => 'She started walking faster because the train was about to leave.',
                            'duration' => 1.0,
                            'pause_ms' => 0,
                            'alignment' => ['characters' => ['S']],
                            'normalized_alignment' => ['characters' => ['S']],
                            'word_timestamps' => [
                                ['text' => 'She', 'start' => 0.0, 'end' => 0.25, 'start_char' => 0, 'end_char' => 3, 'chunk_index' => 0],
                            ],
                        ],
                    ],
                ],
            );

        $merger = Mockery::mock(LessonAudioChunkMerger::class);
        $merger->shouldReceive('merge')
            ->once()
            ->with(Mockery::on(function (array $chunks) {
                return count($chunks) === 2
                    && (int) ($chunks[0]['pause_ms'] ?? -1) === 0
                    && (int) ($chunks[1]['pause_ms'] ?? -1) === 0;
            }), 'mp3')
            ->andReturn('merged-read-aloud');

        $ttsManager = Mockery::mock(TextToSpeechManager::class);
        $ttsManager->shouldReceive('providerFor')
            ->once()
            ->with('lesson_read_aloud')
            ->andReturn($provider);

        $this->app->instance(TextToSpeechManager::class, $ttsManager);
        $this->app->instance(LessonAudioChunkMerger::class, $merger);

        $lesson = $this->createLesson([
            'analysis_meta' => [
                'existing' => 'value',
            ],
            'original_text' => 'Emma looked at her watch. She started walking faster because the train was about to leave.',
        ]);

        $result = app(GenerateLessonReadAloud::class)->handle($lesson);

        $this->assertNotNull($result->audio_path);
        $this->assertNotNull($result->audio_url);
        Storage::disk('public')->assertExists((string) $result->audio_path);
        $this->assertSame('ready', data_get($result->analysis_meta, 'read_aloud.status'));
        $this->assertSame('en-US-JennyNeural', data_get($result->analysis_meta, 'read_aloud.voice'));
        $this->assertSame('azure', data_get($result->analysis_meta, 'read_aloud.provider'));
        $this->assertSame('en-US', data_get($result->analysis_meta, 'read_aloud.locale'));
        $this->assertSame('-4%', data_get($result->analysis_meta, 'read_aloud.rate'));
        $this->assertSame('chat', data_get($result->analysis_meta, 'read_aloud.style'));
        $this->assertSame('mp3', data_get($result->analysis_meta, 'read_aloud.format'));
        $this->assertSame('read-aloud-quality-v2', data_get($result->analysis_meta, 'read_aloud.generation_version'));
        $this->assertSame(0, data_get($result->analysis_meta, 'read_aloud.config_snapshot.chunk_break_ms'));
        $this->assertSame(520, data_get($result->analysis_meta, 'read_aloud.config_snapshot.paragraph_break_ms'));
        $this->assertSame(0.75, data_get($result->analysis_meta, 'read_aloud.config_snapshot.speed'));
        $this->assertSame('elevenlabs_with_timestamps', data_get($result->analysis_meta, 'read_aloud.config_snapshot.timestamp_mode'));
        $this->assertNotNull(data_get($result->analysis_meta, 'read_aloud.cache_signature'));
        $this->assertSame('value', data_get($result->analysis_meta, 'existing'));
        $this->assertGreaterThanOrEqual(2, (int) data_get($result->analysis_meta, 'read_aloud.chunk_count'));
        $this->assertSame(
            'Emma looked at her watch.',
            data_get($result->analysis_meta, 'read_aloud.chunks.0.text')
        );
        $this->assertSame('chunk', data_get($result->analysis_meta, 'read_aloud.chunks.0.type'));
        $this->assertFalse(data_get($result->analysis_meta, 'read_aloud.chunks.0.ends_paragraph'));
        $this->assertSame('word', data_get($result->analysis_meta, 'read_aloud.sync_precision'));
        $this->assertSame('elevenlabs_with_timestamps', data_get($result->analysis_meta, 'read_aloud.alignment_provider'));
        $this->assertSame('Emma', data_get($result->analysis_meta, 'read_aloud.word_timestamps.0.text'));
        $this->assertSame('She', data_get($result->analysis_meta, 'read_aloud.word_timestamps.2.text'));
        $this->assertSame('Emma looked at her watch.', data_get($result->analysis_meta, 'read_aloud.chunks.0.spoken_text'));
        $this->assertNotNull(data_get($result->analysis_meta, 'read_aloud.timings'));
    }

    public function test_it_does_not_overwrite_existing_successful_read_aloud_data_when_generation_fails(): void
    {
        config()->set('lesson_generation.read_aloud.chunk.max_chars', 80);
        config()->set('lesson_generation.read_aloud.voice_map', []);

        $provider = Mockery::mock(TextToSpeechInterface::class);
        $provider->shouldReceive('synthesizeReadAloudText')
            ->once()
            ->andThrow(new RuntimeException('TTS failed'));

        $ttsManager = Mockery::mock(TextToSpeechManager::class);
        $ttsManager->shouldReceive('providerFor')
            ->once()
            ->with('lesson_read_aloud')
            ->andReturn($provider);

        $this->app->instance(TextToSpeechManager::class, $ttsManager);

        $lesson = $this->createLesson([
            'analysis_meta' => [
                'read_aloud' => [
                    'status' => 'ready',
                    'audio_url' => '/storage/lessons/1/read-aloud/old.mp3',
                ],
            ],
            'original_text' => 'Emma looked at her watch.',
        ]);

        $this->expectException(RuntimeException::class);

        try {
            app(GenerateLessonReadAloud::class)->handle($lesson);
        } finally {
            $fresh = $lesson->fresh();
            $this->assertSame('ready', data_get($fresh->analysis_meta, 'read_aloud.status'));
            $this->assertSame('/storage/lessons/1/read-aloud/old.mp3', data_get($fresh->analysis_meta, 'read_aloud.audio_url'));
        }
    }

    protected function createLesson(array $lessonOverrides = []): Lesson
    {
        $user = User::factory()->create();

        $workspace = Workspace::query()->create([
            'owner_id' => $user->id,
            'name' => 'Workspace',
            'slug' => 'workspace-read-aloud-' . uniqid(),
            'target_language' => 'en',
            'support_language' => 'fa',
        ]);

        return Lesson::query()->create(array_merge([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Lesson',
            'resource_type' => 'text',
            'source_url' => null,
            'original_text' => 'Emma looked at her watch.',
            'language' => 'en',
            'level' => 'B1',
            'short_description' => 'Story',
            'status' => 'ready',
        ], $lessonOverrides));
    }
}
