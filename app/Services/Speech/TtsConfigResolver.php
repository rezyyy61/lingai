<?php

namespace App\Services\Speech;

use App\Services\AzureSpeech\AzureSpeechTtsTextService;
use RuntimeException;

class TtsConfigResolver
{
    public function __construct(
        protected AzureSpeechTtsTextService $voices,
    ) {}

    public function localeForLanguage(?string $languageCode): string
    {
        $code = strtolower(trim((string) $languageCode));
        $fallback = trim((string) config('lesson_generation.read_aloud.locale_fallback', 'en-US'));

        if ($code === '') {
            return $fallback !== '' ? $fallback : 'en-US';
        }

        $map = (array) config('lesson_generation.read_aloud.locale_map', []);
        foreach ($map as $prefix => $locale) {
            $prefix = strtolower(trim((string) $prefix));
            if ($prefix !== '' && ($code === $prefix || str_starts_with($code, $prefix . '-'))) {
                return (string) $locale;
            }
        }

        return $fallback !== '' ? $fallback : 'en-US';
    }

    public function voiceForLocale(string $locale, ?string $style = null, ?string $explicitVoice = null): string
    {
        return $this->voiceForLocaleUsingProvider($locale, $style, $explicitVoice, 'azure');
    }

    public function voiceForLocaleUsingProvider(
        string $locale,
        ?string $style = null,
        ?string $explicitVoice = null,
        ?string $provider = null,
    ): string
    {
        $explicitVoice = trim((string) $explicitVoice);
        if ($explicitVoice !== '') {
            return $explicitVoice;
        }

        $provider = $this->normalizeProvider($provider);
        if ($provider === 'elevenlabs') {
            $configured = trim((string) config('services.tts.elevenlabs.voice_id', ''));

            if ($configured === '') {
                throw new RuntimeException('No ElevenLabs voice is configured for TTS generation.');
            }

            return $configured;
        }

        $configured = trim((string) config('lesson_generation.read_aloud.voice', ''));
        if ($configured !== '') {
            return $configured;
        }

        $voiceMap = config('lesson_generation.read_aloud.voice_map', []);
        if (is_array($voiceMap)) {
            $mapped = trim((string) ($voiceMap[$locale] ?? ''));
            if ($mapped !== '') {
                return $mapped;
            }
        }

        return $this->voices->pickVoiceShortName($locale, 'Female', $style)
            ?: $this->voices->pickVoiceShortName($locale, 'Female', null)
            ?: $this->voices->pickVoiceShortName($locale, 'Male', $style)
            ?: $this->voices->pickVoiceShortName($locale, 'Male', null)
            ?: throw new RuntimeException('No Azure Speech voice is available for TTS generation.');
    }

    public function styleForLocale(string $locale): ?string
    {
        $style = trim((string) config('lesson_generation.read_aloud.style', ''));
        if ($style !== '') {
            return $style;
        }

        $styleMap = config('lesson_generation.read_aloud.style_map', []);
        if (is_array($styleMap)) {
            $mapped = trim((string) ($styleMap[$locale] ?? ''));
            if ($mapped !== '') {
                return $mapped;
            }
        }

        return null;
    }

    public function rate(): string
    {
        $rate = trim((string) config('lesson_generation.read_aloud.rate', '-8%'));

        return $rate !== '' ? $rate : '0%';
    }

    public function outputFormat(): string
    {
        $format = trim((string) config('lesson_generation.shadowing_tts.output_format', 'audio-24khz-160kbitrate-mono-mp3'));

        return $format !== '' ? $format : 'audio-24khz-160kbitrate-mono-mp3';
    }

    public function generationVersion(): string
    {
        $version = trim((string) config('lesson_generation.read_aloud.generation_version', 'read-aloud-voice-pacing-v3'));

        return $version !== '' ? $version : 'read-aloud-voice-pacing-v3';
    }

    public function configSnapshot(
        string $feature,
        string $locale,
        string $voice,
        ?string $style,
        string $outputFormat,
        array $extra = [],
        ?string $provider = null,
    ): array {
        return array_merge([
            'feature' => $feature,
            'version' => $this->generationVersion(),
            'provider' => $this->metadataProviderName($provider),
            'locale' => $locale,
            'voice' => $voice,
            'rate' => $this->rate(),
            'style' => $style,
            'output_format' => $outputFormat,
        ], $extra);
    }

    protected function metadataProviderName(?string $provider): string
    {
        return match ($this->normalizeProvider($provider)) {
            'elevenlabs' => 'elevenlabs_http',
            default => 'azure_speech_rest',
        };
    }

    protected function normalizeProvider(?string $provider): string
    {
        $provider = strtolower(trim((string) ($provider ?? 'azure')));

        return match ($provider) {
            '', 'azure', 'azure_speech' => 'azure',
            'elevenlabs' => 'elevenlabs',
            default => $provider,
        };
    }
}
