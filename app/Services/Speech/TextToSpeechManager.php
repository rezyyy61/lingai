<?php

namespace App\Services\Speech;

use App\Services\Speech\Contracts\TextToSpeechInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TextToSpeechManager
{
    public function __construct(
        protected AzureTextToSpeechService $azure,
        protected ElevenLabsTextToSpeechService $elevenLabs,
    ) {}

    public function providerFor(string $feature): TextToSpeechInterface
    {
        $providerName = $this->configuredProviderName();
        $provider = $this->providerByName($providerName);

        if ($provider->supportsFeature($feature)) {
            return $provider;
        }

        $fallbackName = $this->configuredFallbackProviderName();
        if ($fallbackName !== null && $fallbackName !== $providerName) {
            $fallback = $this->providerByName($fallbackName);

            if ($fallback->supportsFeature($feature)) {
                Log::warning('Configured TTS provider does not support feature, using fallback provider', [
                    'feature' => $feature,
                    'configured_provider' => $providerName,
                    'fallback_provider' => $fallbackName,
                ]);

                return $fallback;
            }
        }

        throw new RuntimeException(sprintf(
            'TTS provider [%s] does not support feature [%s], and no valid fallback is configured.',
            $providerName,
            $feature
        ));
    }

    public function configuredProviderName(): string
    {
        return $this->normalizeProviderName((string) config('services.tts.provider', 'azure'));
    }

    protected function configuredFallbackProviderName(): ?string
    {
        $fallback = trim((string) config('services.tts.fallback_provider', 'azure'));

        if ($fallback === '') {
            return null;
        }

        return $this->normalizeProviderName($fallback);
    }

    protected function providerByName(string $provider): TextToSpeechInterface
    {
        return match ($this->normalizeProviderName($provider)) {
            'elevenlabs' => $this->elevenLabs,
            'azure' => $this->azure,
            default => throw new RuntimeException(sprintf('Unsupported TTS provider [%s].', $provider)),
        };
    }

    protected function normalizeProviderName(string $provider): string
    {
        $provider = strtolower(trim($provider));

        return match ($provider) {
            '', 'azure', 'azure_speech' => 'azure',
            'elevenlabs' => 'elevenlabs',
            default => $provider,
        };
    }
}
