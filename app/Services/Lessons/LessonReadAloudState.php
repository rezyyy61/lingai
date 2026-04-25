<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Services\Speech\TextToSpeechManager;
use App\Services\Speech\TtsConfigResolver;
use Illuminate\Support\Carbon;

class LessonReadAloudState
{
    public function __construct(
        protected TextToSpeechManager $ttsManager,
        protected TtsConfigResolver $ttsConfig,
    ) {}

    public function get(Lesson $lesson): array
    {
        $meta = is_array($lesson->analysis_meta) ? $lesson->analysis_meta : [];
        $readAloud = is_array(data_get($meta, 'read_aloud')) ? data_get($meta, 'read_aloud') : [];
        $status = $this->normalizedStatus($readAloud);
        $audioUrl = trim((string) ($readAloud['audio_url'] ?? ''));

        return [
            'status' => in_array($status, ['pending', 'processing', 'ready', 'failed'], true) ? $status : 'pending',
            'exists' => $audioUrl !== '',
            'audio_url' => $audioUrl !== '' ? $audioUrl : null,
            'generated_at' => $readAloud['generated_at'] ?? null,
            'voice' => $readAloud['voice'] ?? null,
            'locale' => $readAloud['locale'] ?? null,
            'rate' => $readAloud['rate'] ?? null,
            'format' => $readAloud['format'] ?? null,
            'chunk_count' => isset($readAloud['chunk_count']) ? (int) $readAloud['chunk_count'] : null,
            'generation_version' => $readAloud['generation_version'] ?? null,
            'current_generation_version' => $this->currentGenerationVersion(),
            'cache_signature' => $readAloud['cache_signature'] ?? null,
            'current_cache_signature' => $this->currentCacheSignature($readAloud),
            'is_stale' => $this->isStale($readAloud),
            'config_snapshot' => is_array($readAloud['config_snapshot'] ?? null) ? $readAloud['config_snapshot'] : null,
            'sync_precision' => $readAloud['sync_precision'] ?? null,
            'alignment_provider' => $readAloud['alignment_provider'] ?? null,
            'alignment_note' => $readAloud['alignment_note'] ?? null,
            'chunks' => is_array($readAloud['chunks'] ?? null) ? $readAloud['chunks'] : null,
            'word_timestamps' => is_array($readAloud['word_timestamps'] ?? null) ? $readAloud['word_timestamps'] : null,
            'timings' => is_array($readAloud['timings'] ?? null) ? $readAloud['timings'] : null,
        ];
    }

    protected function currentGenerationVersion(): string
    {
        $version = trim((string) config('lesson_generation.read_aloud.generation_version', 'read-aloud-voice-pacing-v3'));

        return $version !== '' ? $version : 'read-aloud-voice-pacing-v3';
    }

    protected function isStale(array $readAloud): bool
    {
        $audioUrl = trim((string) ($readAloud['audio_url'] ?? ''));
        if ($audioUrl === '') {
            return false;
        }

        $existing = trim((string) ($readAloud['generation_version'] ?? ''));

        if ($existing !== $this->currentGenerationVersion()) {
            return true;
        }

        $currentCacheSignature = $this->currentCacheSignature($readAloud);
        $existingCacheSignature = trim((string) ($readAloud['cache_signature'] ?? ''));

        return $existingCacheSignature !== '' && $existingCacheSignature !== $currentCacheSignature
            ? true
            : ($existingCacheSignature === '' && $this->hasReadAloudCacheSignatureInputs());
    }

    protected function currentCacheSignature(array $readAloud = []): string
    {
        $provider = $this->ttsManager->configuredProviderName();
        $locale = trim((string) ($readAloud['locale'] ?? '')) ?: $this->ttsConfig->localeForLanguage(null);
        $style = $this->ttsConfig->styleForLocale($locale);
        $voice = $this->ttsConfig->voiceForLocaleUsingProvider($locale, $style, null, $provider);

        $signature = [
            'provider' => $provider,
            'locale' => $locale,
            'voice' => $voice,
            'style' => $style,
            'format' => strtolower(trim((string) config('lesson_generation.read_aloud.format', 'mp3'))) === 'wav' ? 'wav' : 'mp3',
            'output_format' => $provider === 'elevenlabs'
                ? (trim((string) config('services.tts.elevenlabs.read_aloud.output_format', '')) ?: trim((string) config('services.tts.elevenlabs.output_format', 'mp3_44100_128')))
                : 'riff-24khz-16bit-mono-pcm',
            'model_id' => $provider === 'elevenlabs'
                ? (trim((string) config('services.tts.elevenlabs.read_aloud.model_id', '')) ?: trim((string) config('services.tts.elevenlabs.model_id', '')))
                : null,
            'speed' => $provider === 'elevenlabs'
                ? (float) data_get(config('services.tts.elevenlabs.read_aloud.voice_settings', []), 'speed', 0.75)
                : null,
            'timestamp_mode' => $provider === 'elevenlabs' ? 'elevenlabs_with_timestamps' : null,
            'chunk_max_chars' => (int) config('lesson_generation.read_aloud.chunk.max_chars', 1600),
            'chunk_break_ms' => max(0, (int) config('lesson_generation.read_aloud.chunk_break_ms', config('lesson_generation.read_aloud.break_ms', 0))),
            'paragraph_break_ms' => max(0, (int) config('lesson_generation.read_aloud.paragraph_break_ms', 520)),
            'sentence_break_ms' => max(0, (int) config('lesson_generation.read_aloud.sentence_break_ms', 140)),
            'generation_version' => $this->currentGenerationVersion(),
        ];

        return hash('sha256', json_encode($signature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function hasReadAloudCacheSignatureInputs(): bool
    {
        return true;
    }

    public function isProcessingStale(array $readAloud): bool
    {
        if ((string) ($readAloud['status'] ?? 'pending') !== 'processing') {
            return false;
        }

        $startedAt = trim((string) ($readAloud['started_at'] ?? ''));

        if ($startedAt === '') {
            return true;
        }

        try {
            return Carbon::parse($startedAt)->diffInSeconds(now()) >= $this->processingTimeoutSeconds();
        } catch (\Throwable) {
            return true;
        }
    }

    protected function normalizedStatus(array $readAloud): string
    {
        if ($this->isProcessingStale($readAloud)) {
            return 'failed';
        }

        $status = (string) ($readAloud['status'] ?? 'pending');

        return in_array($status, ['pending', 'processing', 'ready', 'failed'], true) ? $status : 'pending';
    }

    protected function processingTimeoutSeconds(): int
    {
        return max(60, (int) config('lesson_generation.read_aloud.processing_timeout_seconds', 300));
    }
}
