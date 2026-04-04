<?php

namespace Tests\Unit\Http\Controllers\Api;

use App\Http\Controllers\Api\LessonSentenceTtsController;
use App\Models\Lesson;
use App\Models\LessonSentence;
use App\Services\AzureSpeech\AzureSpeechTtsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class LessonSentenceTtsControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_generates_shadowing_audio_and_updates_sentence_metadata(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('lesson_tts/sentence.mp3', str_repeat('A', 512));

        $sentence = Mockery::mock(LessonSentence::class)->makePartial();
        $sentence->forceFill([
            'text' => 'Maybe this delay is not so bad after all.',
            'tts_audio_path' => null,
            'meta' => null,
        ]);
        $sentence->setRelation('lesson', new Lesson([
            'target_language' => 'en',
        ]));

        $sentence->shouldReceive('update')
            ->once()
            ->with(Mockery::on(function (array $payload) {
                return ($payload['tts_audio_path'] ?? null) === 'lesson_tts/sentence.mp3'
                    && data_get($payload, 'meta.shadowing_tts.preset') === 'standard'
                    && data_get($payload, 'meta.shadowing_tts.locale') === 'en-US'
                    && data_get($payload, 'meta.shadowing_tts.voice') === 'en-US-JennyNeural'
                    && data_get($payload, 'meta.shadowing_tts.sequence.repeat_pause_ms') === 1550;
            }))
            ->andReturn(true);

        $tts = Mockery::mock(AzureSpeechTtsService::class);
        $tts->shouldReceive('synthesizeShadowingDetailed')
            ->once()
            ->with('Maybe this delay is not so bad after all.', 'en', null, 'slow', 'standard')
            ->andReturn([
                'path' => 'lesson_tts/sentence.mp3',
                'url' => Storage::disk('public')->url('lesson_tts/sentence.mp3'),
                'disk' => 'public',
                'voice' => 'en-US-JennyNeural',
                'locale' => 'en-US',
                'style' => 'narration-professional',
                'preset' => 'standard',
                'output_format' => 'audio-24khz-48kbitrate-mono-mp3',
                'generated_at' => now()->toIso8601String(),
                'sequence' => [
                    'first_pass_rate' => '0%',
                    'second_pass_rate' => '-12%',
                    'final_pass_rate' => '0%',
                    'between_first_and_second_pause_ms' => 420,
                    'repeat_pause_ms' => 1550,
                    'final_tail_pause_ms' => 220,
                ],
            ]);

        $request = Request::create('/api/lesson-sentences/1/tts?preset=standard', 'GET');

        $response = app(LessonSentenceTtsController::class)->show($request, $sentence, $tts);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Storage::disk('public')->url('lesson_tts/sentence.mp3'), $response->getData(true)['audio_url']);
    }
}
