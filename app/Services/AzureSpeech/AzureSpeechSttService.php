<?php

namespace App\Services\AzureSpeech;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AzureSpeechSttService
{
    public function transcribeWav(string $wavBinary, ?string $languageCode = null): array
    {
        $locale = $this->toAzureLocale($languageCode);
        $url = $this->sttUrl($locale);

        $res = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token(),
            'Content-Type' => 'audio/wav; codecs=audio/pcm; samplerate=16000',
            'Accept' => 'application/json',
        ])->withBody($wavBinary, 'audio/wav')->post($url);

        if (! $res->successful()) {
            throw new \RuntimeException('Azure STT failed: ' . $res->status() . ' ' . mb_substr((string) $res->body(), 0, 800));
        }

        $json = $res->json() ?? [];
        $text = (string) ($json['DisplayText'] ?? '');

        $nbest = $json['NBest'][0] ?? null;
        $confidence = is_array($nbest) ? (float) ($nbest['Confidence'] ?? 0) : null;

        return [
            'text' => trim($text),
            'confidence' => $confidence,
            'raw' => $json,
        ];
    }

    protected function token(): string
    {
        return Cache::remember('azure_speech_token', 540, function () {
            $res = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->key(),
                'Content-Length' => '0',
            ])->post($this->tokenUrl());

            if (! $res->successful()) {
                throw new \RuntimeException('Azure Speech token failed: ' . $res->status() . ' ' . mb_substr((string) $res->body(), 0, 800));
            }

            return trim((string) $res->body());
        });
    }

    protected function tokenUrl(): string
    {
        $region = config('services.azure_speech.region');
        return 'https://' . $region . '.api.cognitive.microsoft.com/sts/v1.0/issueToken';
    }

    protected function sttUrl(string $locale): string
    {
        $region = config('services.azure_speech.region');
        return 'https://' . $region . '.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?language='
            . urlencode($locale) . '&format=detailed';
    }

    protected function key(): string
    {
        $key = config('services.azure_speech.key');
        if (! $key) {
            throw new \RuntimeException('AZURE_SPEECH_KEY is missing.');
        }
        return $key;
    }

    protected function toAzureLocale(?string $languageCode): string
    {
        $code = strtolower(trim((string) $languageCode));
        if ($code === '') return 'en-US';
        if (str_starts_with($code, 'en')) return 'en-US';
        if (str_starts_with($code, 'nl')) return 'nl-NL';
        if (str_starts_with($code, 'fa')) return 'fa-IR';
        return 'en-US';
    }
}
