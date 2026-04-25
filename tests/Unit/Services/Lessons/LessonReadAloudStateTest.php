<?php

namespace Tests\Unit\Services\Lessons;

use App\Models\Lesson;
use App\Services\Lessons\LessonReadAloudState;
use Tests\TestCase;

class LessonReadAloudStateTest extends TestCase
{
    public function test_it_marks_existing_audio_without_current_generation_version_as_stale(): void
    {
        config()->set('lesson_generation.read_aloud.generation_version', 'read-aloud-voice-pacing-v3');

        $lesson = new Lesson([
            'analysis_meta' => [
                'read_aloud' => [
                    'status' => 'ready',
                    'audio_url' => '/storage/lessons/1/read-aloud/old.mp3',
                ],
            ],
        ]);

        $state = app(LessonReadAloudState::class)->get($lesson);

        $this->assertTrue($state['is_stale']);
        $this->assertSame('read-aloud-voice-pacing-v3', $state['current_generation_version']);
    }

    public function test_it_does_not_mark_current_generation_as_stale(): void
    {
        config()->set('lesson_generation.read_aloud.generation_version', 'read-aloud-voice-pacing-v3');

        $lesson = new Lesson([
            'analysis_meta' => [
                'read_aloud' => [
                    'status' => 'ready',
                    'audio_url' => '/storage/lessons/1/read-aloud/current.mp3',
                    'generation_version' => 'read-aloud-voice-pacing-v3',
                    'cache_signature' => app(LessonReadAloudState::class)->get(new Lesson([
                        'analysis_meta' => [
                            'read_aloud' => [
                                'status' => 'ready',
                                'audio_url' => '/storage/lessons/1/read-aloud/current.mp3',
                                'locale' => 'en-US',
                            ],
                        ],
                    ]))['current_cache_signature'],
                    'locale' => 'en-US',
                ],
            ],
        ]);

        $state = app(LessonReadAloudState::class)->get($lesson);

        $this->assertFalse($state['is_stale']);
    }

    public function test_it_marks_existing_audio_as_stale_when_read_aloud_cache_signature_changes(): void
    {
        config()->set('lesson_generation.read_aloud.generation_version', 'read-aloud-voice-pacing-v3');

        $lesson = new Lesson([
            'analysis_meta' => [
                'read_aloud' => [
                    'status' => 'ready',
                    'audio_url' => '/storage/lessons/1/read-aloud/current.mp3',
                    'generation_version' => 'read-aloud-voice-pacing-v3',
                    'cache_signature' => 'old-fast-audio-signature',
                    'locale' => 'en-US',
                ],
            ],
        ]);

        $state = app(LessonReadAloudState::class)->get($lesson);

        $this->assertTrue($state['is_stale']);
        $this->assertNotSame('old-fast-audio-signature', $state['current_cache_signature']);
    }

    public function test_it_exposes_playback_state_with_timing_metadata_when_available(): void
    {
        config()->set('lesson_generation.read_aloud.generation_version', 'read-aloud-voice-pacing-v3');

        $lesson = new Lesson([
            'analysis_meta' => [
                'read_aloud' => [
                    'status' => 'ready',
                    'audio_url' => '/storage/lessons/1/read-aloud/current.mp3',
                    'voice' => 'en-US-GuyNeural',
                    'generation_version' => 'read-aloud-voice-pacing-v3',
                    'sync_precision' => 'word',
                    'alignment_provider' => 'elevenlabs_with_timestamps',
                    'word_timestamps' => [
                        ['text' => 'Hello', 'start' => 0, 'end' => 0.4],
                    ],
                    'timings' => [
                        ['text' => 'Hello', 'start' => 0, 'end' => 0.4],
                    ],
                    'chunks' => [
                        ['index' => 0, 'spoken_text' => 'Hello world.'],
                    ],
                ],
            ],
        ]);

        $state = app(LessonReadAloudState::class)->get($lesson);

        $this->assertSame('ready', $state['status']);
        $this->assertSame('/storage/lessons/1/read-aloud/current.mp3', $state['audio_url']);
        $this->assertSame('en-US-GuyNeural', $state['voice']);
        $this->assertSame('word', $state['sync_precision']);
        $this->assertSame('elevenlabs_with_timestamps', $state['alignment_provider']);
        $this->assertSame('Hello', data_get($state, 'word_timestamps.0.text'));
        $this->assertSame('Hello world.', data_get($state, 'chunks.0.spoken_text'));
        $this->assertSame('Hello', data_get($state, 'timings.0.text'));
    }

    public function test_it_keeps_timing_fields_null_when_metadata_is_missing(): void
    {
        config()->set('lesson_generation.read_aloud.generation_version', 'read-aloud-voice-pacing-v3');

        $lesson = new Lesson([
            'analysis_meta' => [
                'read_aloud' => [
                    'status' => 'ready',
                    'audio_url' => '/storage/lessons/1/read-aloud/current.mp3',
                ],
            ],
        ]);

        $state = app(LessonReadAloudState::class)->get($lesson);

        $this->assertNull($state['sync_precision']);
        $this->assertNull($state['alignment_provider']);
        $this->assertNull($state['word_timestamps']);
        $this->assertNull($state['timings']);
        $this->assertNull($state['chunks']);
    }

    public function test_it_marks_stale_processing_state_as_failed_for_ui_polling(): void
    {
        config()->set('lesson_generation.read_aloud.processing_timeout_seconds', 300);

        $lesson = new Lesson([
            'analysis_meta' => [
                'read_aloud' => [
                    'status' => 'processing',
                    'started_at' => now()->subMinutes(10)->toIso8601String(),
                ],
            ],
        ]);

        $state = app(LessonReadAloudState::class)->get($lesson);

        $this->assertSame('failed', $state['status']);
    }
}
