<?php

namespace Tests\Unit\Http\Controllers\Api;

use App\Http\Controllers\Api\LessonWordTtsController;
use App\Models\Lesson;
use App\Models\LessonWord;
use App\Services\Speech\Contracts\TextToSpeechInterface;
use App\Services\Speech\TextToSpeechManager;
use App\Services\Speech\TtsConfigResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class LessonWordTtsControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_regenerates_legacy_flashcard_audio_and_stores_shared_tts_metadata(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('lesson_tts/old-word.mp3', str_repeat('O', 512));
        Storage::disk('public')->put('lesson_tts/new-word.mp3', str_repeat('N', 512));

        $word = Mockery::mock(LessonWord::class)->makePartial();
        $word->forceFill([
            'term' => 'suggested',
            'tts_audio_path' => 'lesson_tts/old-word.mp3',
            'meta' => [
                'flashcard_tts' => [
                    'generation_version' => 'legacy-shadowing-v1',
                ],
            ],
        ]);
        $word->setRelation('lesson', new Lesson([
            'target_language' => 'en',
        ]));

        $word->shouldReceive('update')
            ->once()
            ->with(Mockery::on(function (array $payload) {
                return ($payload['tts_audio_path'] ?? null) === 'lesson_tts/new-word.mp3'
                    && data_get($payload, 'meta.flashcard_tts.locale') === 'en-US'
                    && data_get($payload, 'meta.flashcard_tts.voice') === 'en-US-GuyNeural'
                    && data_get($payload, 'meta.flashcard_tts.generation_version') === 'read-aloud-voice-pacing-v3'
                    && data_get($payload, 'meta.flashcard_tts.config_snapshot.rate') === '-8%';
            }))
            ->andReturn(true);

        $provider = Mockery::mock(TextToSpeechInterface::class);
        $provider->shouldReceive('synthesizeShadowingDetailed')
            ->once()
            ->with('suggested', 'en', null, 'slow', null, 'practice_flashcard')
            ->andReturn([
                'path' => 'lesson_tts/new-word.mp3',
                'url' => Storage::disk('public')->url('lesson_tts/new-word.mp3'),
                'disk' => 'public',
                'voice' => 'en-US-GuyNeural',
                'locale' => 'en-US',
                'style' => null,
                'preset' => 'beginner',
                'output_format' => 'audio-24khz-160kbitrate-mono-mp3',
                'generation_version' => 'read-aloud-voice-pacing-v3',
                'config_snapshot' => [
                    'feature' => 'practice_flashcard',
                    'version' => 'read-aloud-voice-pacing-v3',
                    'provider' => 'azure_speech_rest',
                    'locale' => 'en-US',
                    'voice' => 'en-US-GuyNeural',
                    'rate' => '-8%',
                    'style' => null,
                    'output_format' => 'audio-24khz-160kbitrate-mono-mp3',
                    'preset' => 'beginner',
                    'base_rate' => '-8%',
                ],
                'generated_at' => now()->toIso8601String(),
                'sequence' => [
                    'first_pass_rate' => '-8%',
                    'second_pass_rate' => '-18%',
                    'final_pass_rate' => '-8%',
                    'between_first_and_second_pause_ms' => 520,
                    'repeat_pause_ms' => 2100,
                    'final_tail_pause_ms' => 260,
                ],
            ]);

        $ttsManager = Mockery::mock(TextToSpeechManager::class);
        $ttsManager->shouldReceive('providerFor')
            ->once()
            ->with('practice_flashcard')
            ->andReturn($provider);

        $request = Request::create('/api/lesson-words/1/tts', 'GET');

        $response = app(LessonWordTtsController::class)->show($request, $word, $ttsManager, app(TtsConfigResolver::class));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Storage::disk('public')->url('lesson_tts/new-word.mp3'), $response->getData(true)['audio_url']);
    }
}
