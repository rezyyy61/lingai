<?php

namespace App\Services\OpenAI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OpenAiAudioService
{
    public function synthesizeSpeech(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
    ): string {
        $base = rtrim(config('services.openai.base', 'https://api.openai.com/v1'), '/');
        $model = config('services.openai.tts_model', 'gpt-4o-mini-tts');
        $key = config('services.openai.key');

        $voice = $voice ?? $this->defaultVoiceForLanguage($languageCode);

        $response = Http::withToken($key)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post($base . '/audio/speech', [
                'model' => $model,
                'voice' => $voice,
                'input' => $text,
                'format' => 'mp3',
            ])
            ->throw();

        $binary = $response->body();

        $path = 'lesson_tts/' . Str::uuid() . '.mp3';

        Storage::disk('public')->put($path, $binary);

        return Storage::disk('public')->url($path);
    }

    protected function defaultVoiceForLanguage(?string $languageCode): string
    {

        $code = strtolower($languageCode ?? '');

        return match (true) {
            str_starts_with($code, 'en') => 'alloy',
            str_starts_with($code, 'fa') => 'alloy',
            str_starts_with($code, 'nl') => 'alloy',
            default => 'alloy',
        };
    }
}
