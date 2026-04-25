<?php

namespace App\Services\Speech;

use App\Services\Audio\LessonAudioChunkMerger;
use App\Services\Speech\Contracts\TextToSpeechInterface;
use InvalidArgumentException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ElevenLabsTextToSpeechService implements TextToSpeechInterface
{
    private const SHADOWING_REPETITIONS = 3;
    private const SHADOWING_PAUSE_MARKER = ' ... ';

    public function __construct(
        protected TtsConfigResolver $ttsConfig,
        protected TtsAudioStorage $storage,
        protected LessonAudioChunkMerger $chunkMerger,
        protected ReadAloudTextSegmenter $readAloudSegmenter,
    ) {}

    public function providerName(): string
    {
        return 'elevenlabs';
    }

    public function supportsFeature(string $feature): bool
    {
        return in_array($feature, [
            'practice_shadowing',
            'practice_flashcard',
            'speaking_practice',
            'lesson_audio',
            'lesson_read_aloud',
        ], true);
    }

    public function synthesizeShadowing(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
        string $speed = 'slow',
        ?string $preset = null,
        string $feature = 'practice_shadowing',
    ): string {
        return $this->synthesizeShadowingDetailed(
            text: $text,
            languageCode: $languageCode,
            voice: $voice,
            speed: $speed,
            preset: $preset,
            feature: $feature,
        )['url'];
    }

    public function synthesizeShadowingDetailed(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
        string $speed = 'slow',
        ?string $preset = null,
        string $feature = 'practice_shadowing',
    ): array {
        $presetConfig = $this->shadowingPreset($preset, $speed);
        $shadowingText = $this->prepareShadowingText($text, $presetConfig);
        $locale = $this->ttsConfig->localeForLanguage($languageCode);
        $voice = $this->ttsConfig->voiceForLocaleUsingProvider($locale, null, $voice, $this->providerName());
        $modelId = $this->modelId();
        $outputFormat = trim((string) ($presetConfig['output_format'] ?? '')) ?: $this->outputFormat();
        $disk = (string) config('lesson_generation.shadowing_tts.disk', 'public');
        $voiceSettings = $this->shadowingVoiceSettings();
        $sanitizedVoiceSettings = $this->sanitizeVoiceSettings($voiceSettings);

        Log::notice('ElevenLabs shadowing uses simplified plain-text synthesis instead of Azure SSML pacing.', [
            'feature' => $feature,
            'locale' => $locale,
            'voice' => $voice,
            'preset' => $presetConfig['name'],
            'text_length' => mb_strlen($shadowingText['text']),
            'shadowing_repetition_applied' => $shadowingText['repetition_applied'],
            'has_voice_speed' => is_array($sanitizedVoiceSettings) && array_key_exists('speed', $sanitizedVoiceSettings),
        ]);

        $binary = $this->requestAudio(
            $shadowingText['text'],
            $voice,
            $modelId,
            $outputFormat,
            $voiceSettings,
        );
        $stored = $this->storage->store(
            binary: $binary,
            disk: $disk,
            directory: (string) config('lesson_generation.shadowing_tts.directory', 'lesson_tts'),
            outputFormat: $outputFormat,
        );

        return [
            'path' => $stored['path'],
            'url' => $stored['url'],
            'disk' => $stored['disk'],
            'voice' => $voice,
            'locale' => $locale,
            'style' => null,
            'preset' => (string) $presetConfig['name'],
            'output_format' => $outputFormat,
            'generation_version' => $this->ttsConfig->generationVersion(),
            'config_snapshot' => $this->ttsConfig->configSnapshot(
                feature: $feature,
                locale: $locale,
                voice: $voice,
                style: null,
                outputFormat: $outputFormat,
                extra: [
                    'preset' => (string) $presetConfig['name'],
                    'base_rate' => $this->ttsConfig->rate(),
                    'model_id' => $modelId,
                    'mapping_note' => 'ElevenLabs does not support Azure SSML styles, pitch, or multi-pass timing. The app generates a simplified single-pass audio file.',
                ],
                provider: $this->providerName(),
            ),
            'generated_at' => now()->toIso8601String(),
            'sequence' => [
                'first_pass_rate' => (string) $presetConfig['first_pass_rate'],
                'second_pass_rate' => (string) $presetConfig['second_pass_rate'],
                'final_pass_rate' => (string) $presetConfig['final_pass_rate'],
                'between_first_and_second_pause_ms' => (int) $presetConfig['between_first_and_second_pause_ms'],
                'repeat_pause_ms' => (int) $presetConfig['repeat_pause_ms'],
                'final_tail_pause_ms' => (int) $presetConfig['final_tail_pause_ms'],
            ],
        ];
    }

    public function synthesizeLessonSegment(
        string $text,
        ?string $languageCode = null,
        ?string $speaker = null,
        ?string $style = null,
        string $format = 'wav',
        array $options = [],
    ): array {
        $text = $this->prepareText($text);

        if ($text === '') {
            throw new RuntimeException('Lesson spoken segment text is empty.');
        }

        $locale = $this->ttsConfig->localeForLanguage($languageCode);
        $voice = $this->voiceForSpeaker($speaker, $locale);
        $outputFormat = $this->lessonAudioOutputFormat();
        $modelId = $this->lessonAudioModelId();
        $binary = $this->requestAudio(
            text: $text,
            voiceId: $voice,
            modelId: $modelId,
            outputFormat: $outputFormat,
            voiceSettings: $this->lessonAudioVoiceSettings(),
        );

        return [
            'binary' => $binary,
            'voice' => $voice,
            'format' => $format,
            'input_format' => $outputFormat,
            'locale' => $locale,
            'speaker' => $speaker ? trim($speaker) : null,
            'style' => null,
            'provider' => $this->providerName(),
            'output_format' => $outputFormat,
            'mapping_note' => 'ElevenLabs lesson audio uses plain-text synthesis with stable voice settings. Azure SSML speaking styles are not applied.',
        ];
    }

    public function synthesizeReadAloudText(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
        string $format = 'wav',
        array $options = [],
    ): array {
        $text = $this->prepareText($text);

        if ($text === '') {
            throw new RuntimeException('Read-aloud text is empty.');
        }

        $locale = $this->ttsConfig->localeForLanguage($languageCode);
        $voice = $this->ttsConfig->voiceForLocaleUsingProvider($locale, null, $voice, $this->providerName());
        $outputFormat = $this->readAloudOutputFormat();
        $modelId = $this->readAloudModelId();
        $segments = $this->readAloudSegmenter->segment(
            text: $text,
            sentenceBreakMs: max(0, (int) ($options['sentence_break_ms'] ?? 180)),
            paragraphBreakMs: max(0, (int) ($options['paragraph_break_ms'] ?? 520)),
            maxChars: $this->readAloudSegmentMaxChars(),
        );

        if ($segments === []) {
            throw new RuntimeException('Read-aloud text could not be segmented for ElevenLabs synthesis.');
        }

        $chunks = [];
        $wordTimestamps = [];
        $alignments = [];
        $offsetSeconds = 0.0;

        foreach ($segments as $segmentIndex => $segment) {
            $timedAudio = $this->requestAudioWithTimestamps(
                text: (string) $segment['text'],
                voiceId: $voice,
                modelId: $modelId,
                outputFormat: $outputFormat,
                voiceSettings: $this->readAloudVoiceSettings(),
            );
            $segmentWords = $this->wordTimingsFromAlignment(
                $this->preferredAlignment(
                    $timedAudio['normalized_alignment'] ?? null,
                    $timedAudio['alignment'] ?? null,
                ),
                0,
            );
            $segmentDuration = $this->alignmentDuration(
                $timedAudio['normalized_alignment'] ?? null,
                $timedAudio['alignment'] ?? null,
            );
            $pauseMs = max(0, (int) ($segment['pause_ms'] ?? 0));

            $chunks[] = [
                'binary' => $timedAudio['binary'],
                'pause_ms' => $pauseMs,
                'input_format' => $outputFormat,
            ];

            $wordTimestamps = [
                ...$wordTimestamps,
                ...$this->offsetWordTimings($segmentWords, $offsetSeconds, 0),
            ];

            $alignments[] = [
                'index' => $segmentIndex,
                'text' => (string) $segment['text'],
                'spoken_text' => $this->alignmentText(
                    $timedAudio['normalized_alignment'] ?? null,
                    $timedAudio['alignment'] ?? null,
                ) ?: (string) $segment['text'],
                'pause_ms' => $pauseMs,
                'duration' => $segmentDuration,
                'alignment' => $this->sanitizeAlignmentPayload($timedAudio['alignment'] ?? null),
                'normalized_alignment' => $this->sanitizeAlignmentPayload($timedAudio['normalized_alignment'] ?? null),
                'word_timestamps' => $segmentWords,
            ];

            $offsetSeconds += $segmentDuration + ($pauseMs / 1000);
        }

        return [
            'binary' => $this->chunkMerger->merge($chunks, $format),
            'voice' => $voice,
            'format' => $format,
            'input_format' => $format === 'wav' ? 'wav' : 'mp3',
            'locale' => $locale,
            'style' => null,
            'provider' => $this->providerName(),
            'output_format' => $outputFormat,
            'mapping_note' => 'ElevenLabs read-aloud approximates pacing with sentence-level chunking and concatenated pauses. Azure SSML sentence and paragraph tags are not used.',
            'sync_precision' => $wordTimestamps === [] ? null : 'word',
            'alignment_provider' => $wordTimestamps === [] ? null : 'elevenlabs_with_timestamps',
            'word_timestamps' => $wordTimestamps === [] ? null : $wordTimestamps,
            'timings' => $wordTimestamps === [] ? null : $wordTimestamps,
            'alignments' => $alignments === [] ? null : $alignments,
            'model_id' => $modelId,
            'read_aloud_speed' => $this->extractVoiceSetting($this->readAloudVoiceSettings(), 'speed'),
            'timestamp_mode' => 'elevenlabs_with_timestamps',
        ];
    }

    protected function requestAudio(
        string $text,
        string $voiceId,
        string $modelId,
        string $outputFormat,
        array $voiceSettings = [],
    ): string
    {
        $payload = [
            'text' => $text,
            'model_id' => $modelId,
            'output_format' => $outputFormat,
        ];

        $voiceSettings = $this->sanitizeVoiceSettings($voiceSettings);

        if ($voiceSettings !== null) {
            $payload['voice_settings'] = $voiceSettings;
        }

        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey(),
            'Accept' => 'application/octet-stream',
        ])
            ->timeout((int) config('services.tts.elevenlabs.timeout', 45))
            ->connectTimeout((int) config('services.tts.elevenlabs.connect_timeout', 10))
            ->post($this->endpoint($voiceId), $payload);

        if (! $response->successful()) {
            Log::error('ElevenLabs TTS request failed', [
                'status' => $response->status(),
                'voice_id' => $voiceId,
                'model_id' => $modelId,
                'text_length' => mb_strlen($text),
                'has_voice_settings' => array_key_exists('voice_settings', $payload),
                'has_voice_speed' => array_key_exists('speed', (array) ($payload['voice_settings'] ?? [])),
                'body' => mb_substr((string) $response->body(), 0, 1200),
            ]);

            throw new RuntimeException('ElevenLabs TTS failed: ' . $response->status());
        }

        $binary = (string) $response->body();

        if (mb_strlen($binary) < 200) {
            throw new RuntimeException('ElevenLabs TTS returned empty or invalid audio.');
        }

        return $binary;
    }

    protected function endpoint(string $voiceId): string
    {
        $baseUrl = rtrim((string) config('services.tts.elevenlabs.base_url', 'https://api.elevenlabs.io'), '/');

        return $baseUrl . '/v1/text-to-speech/' . rawurlencode($voiceId);
    }

    protected function timestampsEndpoint(string $voiceId): string
    {
        return $this->endpoint($voiceId) . '/with-timestamps';
    }

    protected function apiKey(): string
    {
        $key = trim((string) config('services.tts.elevenlabs.api_key'));

        if ($key === '') {
            throw new RuntimeException('ELEVENLABS_API_KEY is missing.');
        }

        return $key;
    }

    protected function modelId(): string
    {
        $modelId = trim((string) config('services.tts.elevenlabs.model_id', ''));

        if ($modelId === '') {
            throw new RuntimeException('ELEVENLABS_MODEL_ID is missing.');
        }

        return $modelId;
    }

    protected function outputFormat(): string
    {
        $outputFormat = trim((string) config('services.tts.elevenlabs.output_format', 'mp3_44100_128'));

        return $outputFormat !== '' ? $outputFormat : 'mp3_44100_128';
    }

    protected function prepareText(string $text): string
    {
        $text = html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    protected function requestAudioWithTimestamps(
        string $text,
        string $voiceId,
        string $modelId,
        string $outputFormat,
        array $voiceSettings = [],
    ): array {
        $payload = [
            'text' => $text,
            'model_id' => $modelId,
        ];

        $voiceSettings = $this->sanitizeVoiceSettings($voiceSettings);

        if ($voiceSettings !== null) {
            $payload['voice_settings'] = $voiceSettings;
        }

        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey(),
            'Accept' => 'application/json',
        ])
            ->timeout((int) config('services.tts.elevenlabs.timeout', 45))
            ->connectTimeout((int) config('services.tts.elevenlabs.connect_timeout', 10))
            ->post($this->timestampsEndpoint($voiceId) . '?output_format=' . rawurlencode($outputFormat), $payload);

        if (! $response->successful()) {
            Log::error('ElevenLabs TTS request failed', [
                'status' => $response->status(),
                'voice_id' => $voiceId,
                'model_id' => $modelId,
                'text_length' => mb_strlen($text),
                'has_voice_settings' => array_key_exists('voice_settings', $payload),
                'has_voice_speed' => array_key_exists('speed', (array) ($payload['voice_settings'] ?? [])),
                'body' => mb_substr((string) $response->body(), 0, 1200),
            ]);

            throw new RuntimeException('ElevenLabs TTS failed: ' . $response->status());
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('ElevenLabs TTS timestamps response is invalid.');
        }

        $audioBase64 = trim((string) ($data['audio_base64'] ?? ''));

        if ($audioBase64 === '') {
            throw new RuntimeException('ElevenLabs TTS timestamps response did not include audio.');
        }

        $binary = base64_decode($audioBase64, true);

        if (! is_string($binary) || mb_strlen($binary) < 200) {
            throw new RuntimeException('ElevenLabs TTS returned empty or invalid audio.');
        }

        return [
            'binary' => $binary,
            'alignment' => is_array($data['alignment'] ?? null) ? $data['alignment'] : null,
            'normalized_alignment' => is_array($data['normalized_alignment'] ?? null) ? $data['normalized_alignment'] : null,
        ];
    }

    protected function prepareShadowingText(string $text, array $presetConfig): array
    {
        $text = html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $repetitions = $this->shadowingRepetitions($presetConfig);

        if ($text === '') {
            throw new InvalidArgumentException('TTS text cannot be empty.');
        }

        $segments = $this->extractShadowingSegments($text, $repetitions);

        if ($segments !== null) {
            return [
                'text' => implode(self::SHADOWING_PAUSE_MARKER, $segments),
                'repetition_applied' => false,
            ];
        }

        $text = $this->normalizeShadowingPhrase($this->prepareText($text));

        return [
            'text' => implode(self::SHADOWING_PAUSE_MARKER, array_fill(0, $repetitions, $text)),
            'repetition_applied' => true,
        ];
    }

    protected function shadowingPreset(?string $preset, string $speed): array
    {
        $presets = (array) config('lesson_generation.shadowing_tts.presets', []);
        $defaultName = (string) config('lesson_generation.shadowing_tts.default_preset', 'standard');
        $requested = trim((string) $preset);
        $name = $requested !== '' ? $requested : $this->defaultPresetNameForSpeed($speed);
        $selected = $presets[$name] ?? $presets[$defaultName] ?? $presets['standard'] ?? [];

        return array_merge([
            'name' => $name,
            'repetitions' => self::SHADOWING_REPETITIONS,
            'first_pass_rate' => $this->ttsConfig->rate(),
            'second_pass_rate' => '-12%',
            'final_pass_rate' => $this->ttsConfig->rate(),
            'between_first_and_second_pause_ms' => 420,
            'repeat_pause_ms' => 1550,
            'final_tail_pause_ms' => 220,
            'output_format' => $this->outputFormat(),
        ], $selected, [
            'name' => array_key_exists($name, $presets) ? $name : ($defaultName !== '' ? $defaultName : 'standard'),
        ]);
    }

    protected function shadowingRepetitions(array $presetConfig): int
    {
        return max(1, (int) ($presetConfig['repetitions'] ?? self::SHADOWING_REPETITIONS));
    }

    protected function defaultPresetNameForSpeed(string $speed): string
    {
        return match ($speed) {
            'slow' => 'beginner',
            'fast' => 'intensive',
            default => 'standard',
        };
    }

    protected function voiceForSpeaker(?string $speaker, string $locale): string
    {
        $speaker = strtolower(trim((string) $speaker));
        $configured = config('lesson_generation.audio.speakers', []);
        $coachVoice = is_array($configured) ? trim((string) ($configured['coach'] ?? '')) : '';
        $helperVoice = is_array($configured) ? trim((string) ($configured['helper'] ?? '')) : '';
        $fallback = $this->ttsConfig->voiceForLocaleUsingProvider($locale, null, null, $this->providerName());

        return match ($speaker) {
            'coach' => $coachVoice !== '' ? $coachVoice : $fallback,
            'helper' => $helperVoice !== '' ? $helperVoice : ($coachVoice !== '' ? $coachVoice : $fallback),
            default => $coachVoice !== '' ? $coachVoice : $fallback,
        };
    }

    protected function lessonAudioModelId(): string
    {
        return trim((string) config('services.tts.elevenlabs.lesson_audio.model_id', '')) ?: $this->modelId();
    }

    protected function lessonAudioOutputFormat(): string
    {
        return trim((string) config('services.tts.elevenlabs.lesson_audio.output_format', '')) ?: $this->outputFormat();
    }

    protected function readAloudModelId(): string
    {
        return trim((string) config('services.tts.elevenlabs.read_aloud.model_id', '')) ?: $this->modelId();
    }

    protected function readAloudOutputFormat(): string
    {
        return trim((string) config('services.tts.elevenlabs.read_aloud.output_format', '')) ?: $this->outputFormat();
    }

    protected function readAloudSegmentMaxChars(): int
    {
        return max(120, (int) config('services.tts.elevenlabs.read_aloud.max_chars', 520));
    }

    protected function readAloudVoiceSettings(): array
    {
        return $this->voiceSettingsFromConfig('services.tts.elevenlabs.read_aloud.voice_settings', [
            'stability' => 0.72,
            'similarity_boost' => 0.7,
            'style' => 0.0,
            'use_speaker_boost' => true,
            'speed' => 0.75,
        ]);
    }

    protected function lessonAudioVoiceSettings(): array
    {
        return $this->voiceSettingsFromConfig('services.tts.elevenlabs.lesson_audio.voice_settings', [
            'stability' => 0.62,
            'similarity_boost' => 0.78,
            'style' => 0.08,
            'use_speaker_boost' => true,
        ]);
    }

    protected function shadowingVoiceSettings(): array
    {
        return $this->voiceSettingsFromConfig('services.tts.elevenlabs.shadowing.voice_settings', [
            'stability' => 0.68,
            'similarity_boost' => 0.78,
            'style' => 0.0,
            'use_speaker_boost' => true,
            'speed' => 0.78,
        ]);
    }

    protected function voiceSettingsFromConfig(string $key, array $defaults): array
    {
        $settings = config($key, []);

        if (! is_array($settings)) {
            return $defaults;
        }

        return [
            'stability' => isset($settings['stability']) ? (float) $settings['stability'] : $defaults['stability'],
            'similarity_boost' => isset($settings['similarity_boost']) ? (float) $settings['similarity_boost'] : $defaults['similarity_boost'],
            'style' => isset($settings['style']) ? (float) $settings['style'] : $defaults['style'],
            'use_speaker_boost' => isset($settings['use_speaker_boost']) ? (bool) $settings['use_speaker_boost'] : $defaults['use_speaker_boost'],
            'speed' => array_key_exists('speed', $defaults) || array_key_exists('speed', $settings)
                ? (isset($settings['speed']) ? (float) $settings['speed'] : ($defaults['speed'] ?? null))
                : null,
        ];
    }

    protected function sanitizeVoiceSettings(mixed $voiceSettings): ?array
    {
        if (! $this->isNonEmptyAssociativeArray($voiceSettings)) {
            return null;
        }

        $voiceSettings = array_filter(
            $voiceSettings,
            static fn (mixed $value): bool => $value !== null
        );

        return $this->isNonEmptyAssociativeArray($voiceSettings) ? $voiceSettings : null;
    }

    protected function extractVoiceSetting(array $voiceSettings, string $key): mixed
    {
        $voiceSettings = $this->sanitizeVoiceSettings($voiceSettings) ?? [];

        return $voiceSettings[$key] ?? null;
    }

    protected function preferredAlignment(mixed $normalizedAlignment, mixed $alignment): ?array
    {
        if ($this->isValidAlignmentPayload($normalizedAlignment)) {
            return $normalizedAlignment;
        }

        return $this->isValidAlignmentPayload($alignment) ? $alignment : null;
    }

    protected function isValidAlignmentPayload(mixed $alignment): bool
    {
        return is_array($alignment)
            && is_array($alignment['characters'] ?? null)
            && is_array($alignment['character_start_times_seconds'] ?? null)
            && is_array($alignment['character_end_times_seconds'] ?? null)
            && count($alignment['characters']) > 0
            && count($alignment['characters']) === count($alignment['character_start_times_seconds'])
            && count($alignment['characters']) === count($alignment['character_end_times_seconds']);
    }

    protected function sanitizeAlignmentPayload(mixed $alignment): ?array
    {
        if (! $this->isValidAlignmentPayload($alignment)) {
            return null;
        }

        return [
            'characters' => array_values(array_map(
                static fn (mixed $char): string => (string) $char,
                $alignment['characters']
            )),
            'character_start_times_seconds' => array_values(array_map(
                static fn (mixed $time): float => (float) $time,
                $alignment['character_start_times_seconds']
            )),
            'character_end_times_seconds' => array_values(array_map(
                static fn (mixed $time): float => (float) $time,
                $alignment['character_end_times_seconds']
            )),
        ];
    }

    protected function alignmentText(mixed $normalizedAlignment, mixed $alignment): ?string
    {
        $preferred = $this->preferredAlignment($normalizedAlignment, $alignment);

        return $preferred === null ? null : implode('', array_map(
            static fn (mixed $char): string => (string) $char,
            $preferred['characters']
        ));
    }

    protected function alignmentDuration(mixed $normalizedAlignment, mixed $alignment): float
    {
        $preferred = $this->preferredAlignment($normalizedAlignment, $alignment);

        if ($preferred === null) {
            return 0.0;
        }

        $endTimes = array_map(
            static fn (mixed $time): float => (float) $time,
            $preferred['character_end_times_seconds']
        );

        return $endTimes === [] ? 0.0 : max($endTimes);
    }

    protected function wordTimingsFromAlignment(?array $alignment, int $chunkIndex): array
    {
        if ($alignment === null) {
            return [];
        }

        $characters = array_values($alignment['characters']);
        $starts = array_values($alignment['character_start_times_seconds']);
        $ends = array_values($alignment['character_end_times_seconds']);
        $timings = [];
        $current = null;

        foreach ($characters as $index => $char) {
            $char = (string) $char;

            if ($this->isWordCharacter($char)) {
                if ($current === null) {
                    $current = [
                        'text' => $char,
                        'start' => (float) $starts[$index],
                        'end' => (float) $ends[$index],
                        'start_char' => $index,
                        'end_char' => $index + 1,
                        'chunk_index' => $chunkIndex,
                    ];
                } else {
                    $current['text'] .= $char;
                    $current['end'] = (float) $ends[$index];
                    $current['end_char'] = $index + 1;
                }

                continue;
            }

            if ($current !== null && $this->isWordConnector($char) && isset($characters[$index + 1]) && $this->isWordCharacter((string) $characters[$index + 1])) {
                $current['text'] .= $char;
                $current['end'] = (float) $ends[$index];
                $current['end_char'] = $index + 1;
                continue;
            }

            if ($current !== null) {
                $timings[] = $current;
                $current = null;
            }
        }

        if ($current !== null) {
            $timings[] = $current;
        }

        return array_values(array_filter($timings, static function (array $token): bool {
            return trim((string) ($token['text'] ?? '')) !== '';
        }));
    }

    protected function offsetWordTimings(array $timings, float $offsetSeconds, int $chunkIndex): array
    {
        return array_values(array_map(
            static function (array $timing) use ($offsetSeconds, $chunkIndex): array {
                $timing['start'] = round(((float) ($timing['start'] ?? 0.0)) + $offsetSeconds, 4);
                $timing['end'] = round(((float) ($timing['end'] ?? 0.0)) + $offsetSeconds, 4);
                $timing['chunk_index'] = $chunkIndex;

                return $timing;
            },
            $timings
        ));
    }

    protected function isWordCharacter(string $char): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $char) === 1;
    }

    protected function isWordConnector(string $char): bool
    {
        return in_array($char, ["'", '’', '-'], true);
    }

    protected function isNonEmptyAssociativeArray(mixed $value): bool
    {
        return is_array($value)
            && $value !== []
            && array_keys($value) !== range(0, count($value) - 1);
    }

    protected function extractShadowingSegments(string $text, int $repetitions): ?array
    {
        $patterns = [
            '/\s*\.\.\.\s*/u',
            "/\n\s*\n/u",
        ];

        foreach ($patterns as $pattern) {
            $parts = preg_split($pattern, $text) ?: [];

            if (count($parts) !== $repetitions) {
                continue;
            }

            $normalized = array_map(
                fn (string $part): string => $this->normalizeShadowingPhrase($this->prepareText($part)),
                $parts
            );

            if (in_array('', $normalized, true)) {
                continue;
            }

            if (count(array_unique($normalized)) === 1) {
                return $normalized;
            }
        }

        return null;
    }

    protected function normalizeShadowingPhrase(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (! preg_match('/[.!?…]$/u', $text)) {
            $text .= '.';
        }

        return $text;
    }
}
