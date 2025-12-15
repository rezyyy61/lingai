<?php

namespace App\Services\AzureSpeech;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AzureSpeechTtsService
{
    public function synthesizeShadowing(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
        string $speed = 'slow'
    ): string {
        $text = trim($text);

        if ($text === '') {
            throw new \InvalidArgumentException('Text is empty.');
        }

        $locale = $this->toAzureLocale($languageCode);
        $voice = $voice ?? $this->defaultVoiceForLocale($locale);
        $rate = $this->rateForSpeed($speed);

        $ssml = $this->buildSsml($text, $locale, $voice, $rate);

        $binary = $this->requestTts($ssml);

        $path = 'lesson_tts/' . Str::uuid() . '.mp3';

        Storage::disk('public')->put($path, $binary);

        return Storage::disk('public')->url($path);
    }

    protected function requestTts(string $ssml): string
    {
        $ttsUrl = $this->ttsUrl();
        $token = $this->token();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/ssml+xml',
            'X-Microsoft-OutputFormat' => 'audio-16khz-32kbitrate-mono-mp3',
            'User-Agent' => 'zeel-tts',
        ])->withBody($ssml, 'application/ssml+xml')
            ->post($ttsUrl);

        if (! $response->successful()) {
            throw new \RuntimeException('Azure TTS failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->body();
    }

    protected function token(): string
    {
        return Cache::remember('azure_speech_token', 540, function () {
            $response = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->key(),
                'Content-Length' => '0',
            ])->post($this->tokenUrl());

            if (! $response->successful()) {
                throw new \RuntimeException('Azure Speech token failed: ' . $response->status() . ' ' . $response->body());
            }

            return trim($response->body());
        });
    }

    protected function buildSsml(string $text, string $locale, string $voice, string $rate): string
    {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<speak version="1.0" xml:lang="' . $locale . '" xmlns="http://www.w3.org/2001/10/synthesis">'
            . '<voice name="' . $voice . '">'
            . '<prosody rate="' . $rate . '">'
            . $escaped
            . '</prosody>'
            . '</voice>'
            . '</speak>';
    }

    protected function rateForSpeed(string $speed): string
    {
        return match ($speed) {
            'slow' => '-12%',
            'normal' => '0%',
            'fast' => '+8%',
            default => '0%',
        };
    }

    protected function toAzureLocale(?string $languageCode): string
    {
        $code = strtolower(trim((string) $languageCode));

        if ($code === '') {
            return 'en-US';
        }

        if (str_starts_with($code, 'en')) return 'en-US';
        if (str_starts_with($code, 'nl')) return 'nl-NL';
        if (str_starts_with($code, 'fa')) return 'fa-IR';

        return 'en-US';
    }

    protected function defaultVoiceForLocale(string $locale): string
    {
        return match (true) {
            str_starts_with($locale, 'en-') => 'en-US-JennyNeural',
            str_starts_with($locale, 'nl-') => 'nl-NL-ColetteNeural',
            str_starts_with($locale, 'fa-') => 'fa-IR-DilaraNeural',
            default => 'en-US-JennyNeural',
        };
    }

    protected function tokenUrl(): string
    {
        $region = config('services.azure_speech.region');
        return 'https://' . $region . '.api.cognitive.microsoft.com/sts/v1.0/issueToken';
    }

    protected function ttsUrl(): string
    {
        $region = config('services.azure_speech.region');
        return 'https://' . $region . '.tts.speech.microsoft.com/cognitiveservices/v1';
    }

    protected function key(): string
    {
        $key = config('services.azure_speech.key');

        if (! $key) {
            throw new \RuntimeException('AZURE_SPEECH_KEY is missing.');
        }

        return $key;
    }
}
