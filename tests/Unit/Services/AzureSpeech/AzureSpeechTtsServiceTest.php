<?php

namespace Tests\Unit\Services\AzureSpeech;

use App\Services\AzureSpeech\AzureSpeechTtsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AzureSpeechTtsServiceTest extends TestCase
{
    public function test_it_generates_a_shadowing_sequence_with_configured_preset(): void
    {
        config()->set('services.azure_speech.key', 'test-key');
        config()->set('services.azure_speech.region', 'westeurope');
        config()->set('lesson_generation.shadowing_tts.disk', 'public');
        config()->set('lesson_generation.shadowing_tts.directory', 'lesson_tts');
        config()->set('lesson_generation.shadowing_tts.presets.standard', [
            'first_pass_rate' => '0%',
            'second_pass_rate' => '-14%',
            'final_pass_rate' => '0%',
            'first_pass_pitch' => '0%',
            'second_pass_pitch' => '-1%',
            'final_pass_pitch' => '0%',
            'between_first_and_second_pause_ms' => 400,
            'repeat_pause_ms' => 1600,
            'final_tail_pause_ms' => 250,
            'emphasis_level' => 'moderate',
            'output_format' => 'audio-24khz-48kbitrate-mono-mp3',
        ]);

        Storage::fake('public');

        Http::fake([
            'https://westeurope.api.cognitive.microsoft.com/sts/v1.0/issueToken' => Http::response('fake-token', 200),
            'https://westeurope.tts.speech.microsoft.com/cognitiveservices/v1' => Http::response(str_repeat('A', 512), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $result = app(AzureSpeechTtsService::class)->synthesizeShadowingDetailed(
            text: 'Maybe this delay is not so bad after all.',
            languageCode: 'en',
            voice: null,
            speed: 'normal',
            preset: 'standard',
        );

        $this->assertSame('standard', $result['preset']);
        $this->assertSame('en-US', $result['locale']);
        $this->assertSame('en-US-JennyNeural', $result['voice']);
        $this->assertSame('lesson_tts', dirname((string) $result['path']));
        Storage::disk('public')->assertExists((string) $result['path']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '.tts.speech.microsoft.com')) {
                return false;
            }

            $body = (string) $request->body();

            return str_contains($body, '<prosody rate="0%" pitch="0%">')
                && str_contains($body, '<prosody rate="-14%" pitch="-1%">')
                && str_contains($body, '<break time="400ms"/>')
                && str_contains($body, '<break time="1600ms"/>')
                && str_contains($body, 'Maybe this delay is not so bad after all.');
        });
    }

    public function test_it_retries_with_simpler_ssml_when_styled_sequence_fails(): void
    {
        config()->set('services.azure_speech.key', 'test-key');
        config()->set('services.azure_speech.region', 'westeurope');
        config()->set('lesson_generation.shadowing_tts.disk', 'public');
        config()->set('lesson_generation.shadowing_tts.directory', 'lesson_tts');

        Storage::fake('public');

        Http::fake([
            'https://westeurope.api.cognitive.microsoft.com/sts/v1.0/issueToken' => Http::response('fake-token', 200),
            'https://westeurope.tts.speech.microsoft.com/cognitiveservices/v1' => Http::sequence()
                ->push('style failed', 400, ['Content-Type' => 'application/json'])
                ->push(str_repeat('B', 512), 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $result = app(AzureSpeechTtsService::class)->synthesizeShadowingDetailed(
            text: 'There is another train in 30 minutes.',
            languageCode: 'en',
            voice: null,
            speed: 'slow',
            preset: 'beginner',
        );

        $this->assertSame('beginner', $result['preset']);
        Storage::disk('public')->assertExists((string) $result['path']);

        $ttsRequests = Http::recorded()
            ->filter(static function (array $entry) {
                return str_contains($entry[0]->url(), '.tts.speech.microsoft.com');
            })
            ->values();

        $this->assertCount(2, $ttsRequests);
        $this->assertStringContainsString('mstts:express-as', (string) $ttsRequests[0][0]->body());
        $this->assertStringNotContainsString('mstts:express-as', (string) $ttsRequests[1][0]->body());
    }
}
