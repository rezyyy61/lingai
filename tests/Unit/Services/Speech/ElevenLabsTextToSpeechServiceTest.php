<?php

namespace Tests\Unit\Services\Speech;

use App\Services\Audio\LessonAudioChunkMerger;
use App\Services\Speech\ElevenLabsTextToSpeechService;
use App\Services\Speech\ReadAloudTextSegmenter;
use App\Services\Speech\TtsAudioStorage;
use App\Services\Speech\TtsConfigResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ElevenLabsTextToSpeechServiceTest extends TestCase
{
    public function test_it_generates_shadowing_audio_with_elevenlabs_and_stores_metadata(): void
    {
        config()->set('services.tts.elevenlabs.api_key', 'test-key');
        config()->set('services.tts.elevenlabs.voice_id', 'voice-123');
        config()->set('services.tts.elevenlabs.model_id', 'eleven-multilingual-v2');
        config()->set('services.tts.elevenlabs.output_format', 'mp3_44100_128');
        config()->set('lesson_generation.shadowing_tts.disk', 'public');
        config()->set('lesson_generation.shadowing_tts.directory', 'lesson_tts');

        Storage::fake('public');

        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/voice-123' => Http::response(str_repeat('E', 512), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $result = app(ElevenLabsTextToSpeechService::class)->synthesizeShadowingDetailed(
            text: 'Hello there.',
            languageCode: 'en',
            feature: 'practice_shadowing',
        );

        $this->assertSame('voice-123', $result['voice']);
        $this->assertSame('en-US', $result['locale']);
        $this->assertSame('elevenlabs_http', data_get($result, 'config_snapshot.provider'));
        $this->assertStringContainsString('simplified single-pass', (string) data_get($result, 'config_snapshot.mapping_note'));
        Storage::disk('public')->assertExists((string) $result['path']);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/voice-123'
                && $data['text'] === 'Hello there. ... Hello there. ... Hello there.'
                && ($data['voice_settings'] ?? null) === [
                    'stability' => 0.68,
                    'similarity_boost' => 0.78,
                    'style' => 0.0,
                    'use_speaker_boost' => true,
                    'speed' => 0.78,
                ];
        });
    }

    public function test_it_formats_single_words_for_shadowing_with_pause_markers(): void
    {
        config()->set('services.tts.elevenlabs.api_key', 'test-key');
        config()->set('services.tts.elevenlabs.voice_id', 'voice-123');
        config()->set('services.tts.elevenlabs.model_id', 'eleven-multilingual-v2');
        config()->set('services.tts.elevenlabs.output_format', 'mp3_44100_128');
        config()->set('lesson_generation.shadowing_tts.disk', 'public');
        config()->set('lesson_generation.shadowing_tts.directory', 'lesson_tts');

        Storage::fake('public');

        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/voice-123' => Http::response(str_repeat('E', 512), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        app(ElevenLabsTextToSpeechService::class)->synthesizeShadowingDetailed(
            text: 'apple',
            languageCode: 'en',
            feature: 'practice_flashcard',
        );

        Http::assertSent(function (Request $request) {
            return $request->data()['text'] === 'apple. ... apple. ... apple.';
        });
    }

    public function test_it_generates_lesson_audio_segments_with_elevenlabs(): void
    {
        config()->set('services.tts.elevenlabs.api_key', 'test-key');
        config()->set('services.tts.elevenlabs.voice_id', 'voice-default');
        config()->set('services.tts.elevenlabs.model_id', 'eleven-multilingual-v2');
        config()->set('services.tts.elevenlabs.lesson_audio.output_format', 'mp3_44100_128');
        config()->set('lesson_generation.audio.speakers', [
            'coach' => 'voice-coach',
            'helper' => 'voice-helper',
        ]);

        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/voice-coach' => Http::response(str_repeat('L', 512), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $result = app(ElevenLabsTextToSpeechService::class)->synthesizeLessonSegment(
            text: 'Welcome back. Listen carefully.',
            languageCode: 'en',
            speaker: 'coach',
            style: 'friendly',
            format: 'wav',
            options: [
                'use_case' => 'lesson_audio',
            ],
        );

        $this->assertSame('voice-coach', $result['voice']);
        $this->assertSame('mp3_44100_128', $result['input_format']);
        $this->assertSame('elevenlabs', $result['provider']);
        $this->assertStringContainsString('plain-text synthesis', (string) $result['mapping_note']);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/voice-coach'
                && ($data['voice_settings'] ?? null) === [
                    'stability' => 0.62,
                    'similarity_boost' => 0.78,
                    'style' => 0.08,
                    'use_speaker_boost' => true,
                ];
        });
    }

    public function test_it_generates_read_aloud_audio_with_sentence_level_merging_for_elevenlabs(): void
    {
        config()->set('services.tts.elevenlabs.api_key', 'test-key');
        config()->set('services.tts.elevenlabs.voice_id', 'voice-123');
        config()->set('services.tts.elevenlabs.model_id', 'eleven-multilingual-v2');
        config()->set('services.tts.elevenlabs.read_aloud.output_format', 'mp3_44100_128');
        config()->set('services.tts.elevenlabs.read_aloud.max_chars', 120);

        $merger = Mockery::mock(LessonAudioChunkMerger::class);
        $merger->shouldReceive('merge')
            ->once()
            ->with(Mockery::on(function (array $chunks) {
                return count($chunks) === 2
                    && (int) ($chunks[0]['pause_ms'] ?? -1) === 180
                    && (int) ($chunks[1]['pause_ms'] ?? -1) === 0
                    && ($chunks[0]['input_format'] ?? null) === 'mp3_44100_128';
            }), 'wav')
            ->andReturn('merged-read-aloud-binary');

        $this->app->instance(LessonAudioChunkMerger::class, $merger);

        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/voice-123/with-timestamps?output_format=mp3_44100_128' => Http::response([
                'audio_base64' => base64_encode(str_repeat('R', 512)),
                'alignment' => [
                    'characters' => ['E', 'm', 'm', 'a', ' ', 'l', 'o', 'o', 'k', 'e', 'd', ' ', 'a', 't', ' ', 'h', 'e', 'r', ' ', 'w', 'a', 't', 'c', 'h', '.'],
                    'character_start_times_seconds' => [0.00, 0.04, 0.08, 0.12, 0.16, 0.20, 0.24, 0.28, 0.32, 0.36, 0.40, 0.44, 0.48, 0.52, 0.56, 0.60, 0.64, 0.68, 0.72, 0.76, 0.80, 0.84, 0.88, 0.92, 0.96],
                    'character_end_times_seconds' => [0.04, 0.08, 0.12, 0.16, 0.20, 0.24, 0.28, 0.32, 0.36, 0.40, 0.44, 0.48, 0.52, 0.56, 0.60, 0.64, 0.68, 0.72, 0.76, 0.80, 0.84, 0.88, 0.92, 0.96, 1.00],
                ],
                'normalized_alignment' => [
                    'characters' => ['E', 'm', 'm', 'a', ' ', 'l', 'o', 'o', 'k', 'e', 'd', ' ', 'a', 't', ' ', 'h', 'e', 'r', ' ', 'w', 'a', 't', 'c', 'h', '.'],
                    'character_start_times_seconds' => [0.00, 0.04, 0.08, 0.12, 0.16, 0.20, 0.24, 0.28, 0.32, 0.36, 0.40, 0.44, 0.48, 0.52, 0.56, 0.60, 0.64, 0.68, 0.72, 0.76, 0.80, 0.84, 0.88, 0.92, 0.96],
                    'character_end_times_seconds' => [0.04, 0.08, 0.12, 0.16, 0.20, 0.24, 0.28, 0.32, 0.36, 0.40, 0.44, 0.48, 0.52, 0.56, 0.60, 0.64, 0.68, 0.72, 0.76, 0.80, 0.84, 0.88, 0.92, 0.96, 1.00],
                ],
            ], 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        $result = app(ElevenLabsTextToSpeechService::class)->synthesizeReadAloudText(
            text: 'Emma looked at her watch. She started walking faster.',
            languageCode: 'en',
            format: 'wav',
            options: [
                'sentence_break_ms' => 180,
                'paragraph_break_ms' => 520,
                'use_case' => 'lesson_read_aloud',
            ],
        );

        $this->assertSame('voice-123', $result['voice']);
        $this->assertSame('wav', $result['format']);
        $this->assertSame('merged-read-aloud-binary', $result['binary']);
        $this->assertStringContainsString('sentence-level chunking', (string) $result['mapping_note']);
        $this->assertSame('word', $result['sync_precision']);
        $this->assertSame('elevenlabs_with_timestamps', $result['alignment_provider']);
        $this->assertNotEmpty($result['word_timestamps']);
        $this->assertSame('Emma', data_get($result, 'word_timestamps.0.text'));

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/voice-123/with-timestamps?output_format=mp3_44100_128'
                && ($data['voice_settings'] ?? null) === [
                    'stability' => 0.72,
                    'similarity_boost' => 0.7,
                    'style' => 0.0,
                    'use_speaker_boost' => true,
                    'speed' => 0.75,
                ];
        });
        $this->assertSame(0.75, data_get($result, 'read_aloud_speed'));
        $this->assertSame('eleven_multilingual_v2', data_get($result, 'model_id'));
        $this->assertSame('elevenlabs_with_timestamps', data_get($result, 'timestamp_mode'));
    }

    public function test_it_does_not_repeat_shadowing_text_when_it_is_already_formatted_for_shadowing(): void
    {
        config()->set('services.tts.elevenlabs.api_key', 'test-key');
        config()->set('services.tts.elevenlabs.voice_id', 'voice-123');
        config()->set('services.tts.elevenlabs.model_id', 'eleven-multilingual-v2');
        config()->set('services.tts.elevenlabs.output_format', 'mp3_44100_128');
        config()->set('lesson_generation.shadowing_tts.disk', 'public');
        config()->set('lesson_generation.shadowing_tts.directory', 'lesson_tts');

        Storage::fake('public');

        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/voice-123' => Http::response(str_repeat('E', 512), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $text = 'apple. ... apple. ... apple.';

        app(ElevenLabsTextToSpeechService::class)->synthesizeShadowingDetailed(
            text: $text,
            languageCode: 'en',
            feature: 'practice_flashcard',
        );

        Http::assertSent(function (Request $request) use ($text) {
            return $request->data()['text'] === $text;
        });
    }

    public function test_it_normalizes_legacy_newline_shadowing_text_to_pause_markers_without_multiplying_it(): void
    {
        config()->set('services.tts.elevenlabs.api_key', 'test-key');
        config()->set('services.tts.elevenlabs.voice_id', 'voice-123');
        config()->set('services.tts.elevenlabs.model_id', 'eleven-multilingual-v2');
        config()->set('services.tts.elevenlabs.output_format', 'mp3_44100_128');
        config()->set('lesson_generation.shadowing_tts.disk', 'public');
        config()->set('lesson_generation.shadowing_tts.directory', 'lesson_tts');

        Storage::fake('public');

        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/voice-123' => Http::response(str_repeat('E', 512), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        app(ElevenLabsTextToSpeechService::class)->synthesizeShadowingDetailed(
            text: "apple\n\napple\n\napple",
            languageCode: 'en',
            feature: 'practice_flashcard',
        );

        Http::assertSent(function (Request $request) {
            return $request->data()['text'] === 'apple. ... apple. ... apple.';
        });
    }

    public function test_it_omits_list_voice_settings_from_requests(): void
    {
        config()->set('services.tts.elevenlabs.api_key', 'test-key');
        config()->set('services.tts.elevenlabs.model_id', 'eleven-multilingual-v2');

        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/voice-123' => Http::response(str_repeat('R', 512), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->makeInspectableService()->requestAudioPublic(
            text: 'Test text',
            voiceId: 'voice-123',
            modelId: 'eleven-multilingual-v2',
            outputFormat: 'mp3_44100_128',
            voiceSettings: [],
        );

        Http::assertSent(function (Request $request) {
            return ! array_key_exists('voice_settings', $request->data());
        });
    }

    public function test_it_filters_null_values_from_associative_voice_settings_before_sending(): void
    {
        config()->set('services.tts.elevenlabs.api_key', 'test-key');
        config()->set('services.tts.elevenlabs.model_id', 'eleven-multilingual-v2');

        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/voice-123' => Http::response(str_repeat('R', 512), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->makeInspectableService()->requestAudioPublic(
            text: 'Test text',
            voiceId: 'voice-123',
            modelId: 'eleven-multilingual-v2',
            outputFormat: 'mp3_44100_128',
            voiceSettings: [
                'stability' => 0.5,
                'similarity_boost' => null,
                'style' => 0.0,
                'use_speaker_boost' => true,
            ],
        );

        Http::assertSent(function (Request $request) {
            return ($request->data()['voice_settings'] ?? null) === [
                'stability' => 0.5,
                'style' => 0.0,
                'use_speaker_boost' => true,
            ];
        });
    }

    public function test_it_includes_speed_only_inside_associative_voice_settings(): void
    {
        config()->set('services.tts.elevenlabs.api_key', 'test-key');
        config()->set('services.tts.elevenlabs.model_id', 'eleven-multilingual-v2');

        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/voice-123' => Http::response(str_repeat('R', 512), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->makeInspectableService()->requestAudioPublic(
            text: 'Test text',
            voiceId: 'voice-123',
            modelId: 'eleven-multilingual-v2',
            outputFormat: 'mp3_44100_128',
            voiceSettings: [
                'speed' => 0.8,
            ],
        );

        Http::assertSent(function (Request $request) {
            return ($request->data()['voice_settings'] ?? null) === [
                'speed' => 0.8,
            ];
        });
    }

    private function makeInspectableService(): object
    {
        return new class(
            app(TtsConfigResolver::class),
            app(TtsAudioStorage::class),
            app(LessonAudioChunkMerger::class),
            app(ReadAloudTextSegmenter::class),
        ) extends ElevenLabsTextToSpeechService {
            public function requestAudioPublic(
                string $text,
                string $voiceId,
                string $modelId,
                string $outputFormat,
                array $voiceSettings = [],
            ): string {
                return $this->requestAudio($text, $voiceId, $modelId, $outputFormat, $voiceSettings);
            }
        };
    }
}
