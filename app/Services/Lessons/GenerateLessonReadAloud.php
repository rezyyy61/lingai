<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Services\Audio\LessonAudioChunkMerger;
use App\Services\Speech\TextToSpeechManager;
use App\Services\Speech\TtsConfigResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateLessonReadAloud
{
    public function __construct(
        protected TextToSpeechManager $ttsManager,
        protected ReadAloudTextChunker $chunker,
        protected LessonAudioChunkMerger $chunkMerger,
        protected TtsConfigResolver $ttsConfig,
    ) {}

    public function handle(Lesson $lesson): Lesson
    {
        $text = trim((string) $lesson->original_text);

        if ($text === '') {
            throw new RuntimeException('Lesson original text is required before generating read-aloud audio.');
        }

        $format = $this->format();
        $disk = (string) config('lesson_generation.read_aloud.disk', config('lesson_generation.audio.disk', 'public'));
        $locale = $this->localeForLesson($lesson);
        $style = $this->style($locale);
        $chunkItems = $this->chunker->chunkWithMetadata($text);
        $chunks = array_map(static fn (array $chunk) => (string) $chunk['text'], $chunkItems);

        if ($chunks === []) {
            throw new RuntimeException('Lesson original text could not be chunked for read-aloud audio.');
        }

        $tts = $this->ttsManager->providerFor('lesson_read_aloud');
        $audioChunks = [];
        $timingChunks = [];
        $wordTimestamps = [];
        $offsetSeconds = 0.0;

        foreach ($chunkItems as $index => $chunkItem) {
            $chunk = (string) ($chunkItem['text'] ?? '');
            $audio = $tts->synthesizeReadAloudText(
                text: $chunk,
                languageCode: $lesson->language ?: $lesson->target_language,
                voice: null,
                format: 'wav',
                options: [
                    'use_case' => 'lesson_read_aloud',
                    'pacing_intent' => 'calm_clear_natural',
                    'style' => $style,
                    'rate' => $this->rate(),
                    'sentence_break_ms' => $this->sentenceBreakMs(),
                    'paragraph_break_ms' => $this->paragraphBreakMs(),
                    'output_format' => 'riff-24khz-16bit-mono-pcm',
                ],
            );
            $pauseMs = $this->pauseAfterChunk($chunkItem, $index === array_key_last($chunkItems));

            $audioChunks[] = [
                'binary' => $audio['binary'],
                'pause_ms' => $pauseMs,
                'input_format' => (string) ($audio['input_format'] ?? 'wav'),
            ];

            $chunkWords = $this->prepareChunkWordTimings(
                is_array($audio['word_timestamps'] ?? null) ? $audio['word_timestamps'] : [],
                $offsetSeconds,
                $index,
            );
            $chunkDuration = $this->chunkDurationSeconds($chunkWords, $audio);

            $wordTimestamps = [...$wordTimestamps, ...$chunkWords];
            $timingChunks[] = $this->buildTimingChunk(
                chunkItem: $chunkItem,
                audio: $audio,
                chunkIndex: $index,
                offsetSeconds: $offsetSeconds,
                chunkDuration: $chunkDuration,
                pauseMs: $pauseMs,
                chunkWords: $chunkWords,
            );

            $offsetSeconds += $chunkDuration + ($pauseMs / 1000);
            $lastAudio = $audio;
        }

        if (! isset($lastAudio)) {
            throw new RuntimeException('No read-aloud audio chunks were generated.');
        }

        $binary = $this->chunkMerger->merge($audioChunks, $format);
        $path = $this->buildAudioPath($lesson, $format);
        Storage::disk($disk)->put($path, $binary);
        $url = Storage::disk($disk)->url($path);

        DB::transaction(function () use ($lesson, $path, $url, $disk, $locale, $style, $format, $chunks, $chunkItems, $lastAudio, $timingChunks, $wordTimestamps): void {
            /** @var Lesson $freshLesson */
            $freshLesson = Lesson::query()
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $meta = is_array($freshLesson->analysis_meta) ? $freshLesson->analysis_meta : [];
            $effectiveStyle = array_key_exists('style', $lastAudio) ? $lastAudio['style'] : $style;
            data_set($meta, 'read_aloud.status', 'ready');
            data_set($meta, 'read_aloud.voice', $lastAudio['voice']);
            data_set($meta, 'read_aloud.provider', $lastAudio['provider']);
            data_set($meta, 'read_aloud.mapping_note', $lastAudio['mapping_note'] ?? null);
            data_set($meta, 'read_aloud.locale', $locale);
            data_set($meta, 'read_aloud.rate', $this->rate());
            data_set($meta, 'read_aloud.style', $effectiveStyle);
            data_set($meta, 'read_aloud.format', $format);
            data_set($meta, 'read_aloud.generation_version', $this->generationVersion());
            $configSnapshot = $this->configSnapshot($locale, $lastAudio['voice'], $effectiveStyle, $format, $lastAudio['provider'], $lastAudio);
            data_set($meta, 'read_aloud.config_snapshot', $configSnapshot);
            data_set($meta, 'read_aloud.cache_signature', $this->cacheSignature($configSnapshot));
            data_set($meta, 'read_aloud.generated_at', now()->toIso8601String());
            data_set($meta, 'read_aloud.chunk_count', count($chunks));
            data_set($meta, 'read_aloud.chunks', $timingChunks === [] ? $this->timingChunks($chunkItems) : $timingChunks);
            data_set($meta, 'read_aloud.sync_precision', $lastAudio['sync_precision'] ?? null);
            data_set($meta, 'read_aloud.alignment_provider', $lastAudio['alignment_provider'] ?? null);
            data_set($meta, 'read_aloud.alignment_note', $this->alignmentNote($lastAudio));
            data_set($meta, 'read_aloud.word_timestamps', $wordTimestamps === [] ? null : $wordTimestamps);
            data_forget($meta, 'read_aloud.sentence_timestamps');
            data_set($meta, 'read_aloud.timings', $wordTimestamps === [] ? null : $wordTimestamps);
            data_set($meta, 'read_aloud.break_ms', $this->chunkBreakMs());
            data_set($meta, 'read_aloud.paragraph_break_ms', $this->paragraphBreakMs());
            data_set($meta, 'read_aloud.sentence_break_ms', $this->sentenceBreakMs());
            data_set($meta, 'read_aloud.disk', $disk);
            data_set($meta, 'read_aloud.audio_path', $path);
            data_set($meta, 'read_aloud.audio_url', $url);

            $freshLesson->forceFill([
                'audio_path' => $path,
                'audio_url' => $url,
                'analysis_meta' => $meta,
            ])->save();
        });

        return $lesson->fresh();
    }

    protected function pauseAfterChunk(array $chunk, bool $isLastChunk): int
    {
        if ($isLastChunk) {
            return 0;
        }

        if ((bool) ($chunk['ends_paragraph'] ?? false)) {
            return $this->paragraphBreakMs();
        }

        return $this->chunkBreakMs();
    }

    protected function timingChunks(array $chunks): array
    {
        return array_values(array_map(
            fn (array $chunk, int $index) => [
                'type' => 'chunk',
                'index' => $index,
                'text' => (string) ($chunk['text'] ?? ''),
                'paragraph_index' => (int) ($chunk['paragraph_index'] ?? 0),
                'ends_paragraph' => (bool) ($chunk['ends_paragraph'] ?? false),
            ],
            $chunks,
            array_keys($chunks)
        ));
    }

    protected function prepareChunkWordTimings(array $timings, float $offsetSeconds, int $chunkIndex): array
    {
        return array_values(array_map(
            static function (array $timing) use ($offsetSeconds, $chunkIndex): array {
                return [
                    'text' => (string) ($timing['text'] ?? ''),
                    'start' => round(((float) ($timing['start'] ?? 0.0)) + $offsetSeconds, 4),
                    'end' => round(((float) ($timing['end'] ?? 0.0)) + $offsetSeconds, 4),
                    'start_char' => isset($timing['start_char']) ? (int) $timing['start_char'] : null,
                    'end_char' => isset($timing['end_char']) ? (int) $timing['end_char'] : null,
                    'chunk_index' => $chunkIndex,
                ];
            },
            array_values(array_filter($timings, static function (mixed $timing): bool {
                return is_array($timing) && trim((string) ($timing['text'] ?? '')) !== '';
            }))
        ));
    }

    protected function chunkDurationSeconds(array $chunkWords, array $audio): float
    {
        if ($chunkWords !== []) {
            $ends = array_map(
                static fn (array $timing): float => (float) ($timing['end'] ?? 0.0),
                $chunkWords
            );

            return max(0.0, ...$ends);
        }

        $alignments = is_array($audio['alignments'] ?? null) ? $audio['alignments'] : [];
        $durations = array_map(
            static fn (array $alignment): float => (float) ($alignment['duration'] ?? 0.0),
            array_values(array_filter($alignments, 'is_array'))
        );

        return $durations === [] ? 0.0 : max(0.0, array_sum($durations));
    }

    protected function buildTimingChunk(array $chunkItem, array $audio, int $chunkIndex, float $offsetSeconds, float $chunkDuration, int $pauseMs, array $chunkWords): array
    {
        $alignments = is_array($audio['alignments'] ?? null) ? $audio['alignments'] : [];
        $spokenText = $this->spokenChunkText($alignments, (string) ($chunkItem['text'] ?? ''));

        return [
            'type' => 'chunk',
            'index' => $chunkIndex,
            'text' => (string) ($chunkItem['text'] ?? ''),
            'spoken_text' => $spokenText,
            'paragraph_index' => (int) ($chunkItem['paragraph_index'] ?? 0),
            'ends_paragraph' => (bool) ($chunkItem['ends_paragraph'] ?? false),
            'start' => round($offsetSeconds, 4),
            'end' => round($offsetSeconds + $chunkDuration, 4),
            'duration' => round($chunkDuration, 4),
            'pause_ms' => $pauseMs,
            'word_count' => count($chunkWords),
            'word_timestamps' => $chunkWords === [] ? null : $chunkWords,
            'alignments' => $alignments === [] ? null : $alignments,
        ];
    }

    protected function spokenChunkText(array $alignments, string $fallback): string
    {
        $parts = array_values(array_filter(array_map(
            static fn (array $alignment): string => trim((string) ($alignment['spoken_text'] ?? '')),
            array_values(array_filter($alignments, 'is_array'))
        )));

        return $parts === [] ? $fallback : implode(' ', $parts);
    }

    protected function alignmentNote(array $audio): ?string
    {
        if (($audio['alignment_provider'] ?? null) === null) {
            return null;
        }

        return 'Word-level timings are derived from ElevenLabs character alignment for newly generated read-aloud audio.';
    }

    protected function format(): string
    {
        $format = strtolower(trim((string) config('lesson_generation.read_aloud.format', 'mp3')));

        return $format === 'wav' ? 'wav' : 'mp3';
    }

    protected function rate(): string
    {
        return $this->ttsConfig->rate();
    }

    protected function style(string $locale): ?string
    {
        return $this->ttsConfig->styleForLocale($locale);
    }

    protected function chunkBreakMs(): int
    {
        return max(0, (int) config(
            'lesson_generation.read_aloud.chunk_break_ms',
            config('lesson_generation.read_aloud.break_ms', 0)
        ));
    }

    protected function paragraphBreakMs(): int
    {
        return max(0, (int) config('lesson_generation.read_aloud.paragraph_break_ms', 520));
    }

    protected function sentenceBreakMs(): int
    {
        return max(0, (int) config('lesson_generation.read_aloud.sentence_break_ms', 140));
    }

    protected function generationVersion(): string
    {
        return $this->ttsConfig->generationVersion();
    }

    protected function configSnapshot(string $locale, string $voice, ?string $style, string $format, string $provider, array $audio = []): array
    {
        return [
            ...$this->ttsConfig->configSnapshot(
                feature: 'lesson_read_aloud',
                locale: $locale,
                voice: $voice,
                style: $style,
                outputFormat: $format,
                provider: $provider,
            ),
            'format' => $format,
            'chunk_max_chars' => (int) config('lesson_generation.read_aloud.chunk.max_chars', 1600),
            'chunk_break_ms' => $this->chunkBreakMs(),
            'paragraph_break_ms' => $this->paragraphBreakMs(),
            'sentence_break_ms' => $this->sentenceBreakMs(),
            'model_id' => $audio['model_id'] ?? null,
            'speed' => $audio['read_aloud_speed'] ?? null,
            'timestamp_mode' => $audio['timestamp_mode'] ?? null,
        ];
    }

    protected function cacheSignature(array $configSnapshot): string
    {
        $signature = [
            'provider' => $configSnapshot['provider'] ?? null,
            'locale' => $configSnapshot['locale'] ?? null,
            'voice' => $configSnapshot['voice'] ?? null,
            'style' => $configSnapshot['style'] ?? null,
            'format' => $configSnapshot['format'] ?? null,
            'output_format' => $configSnapshot['output_format'] ?? null,
            'model_id' => $configSnapshot['model_id'] ?? null,
            'speed' => $configSnapshot['speed'] ?? null,
            'timestamp_mode' => $configSnapshot['timestamp_mode'] ?? null,
            'chunk_max_chars' => $configSnapshot['chunk_max_chars'] ?? null,
            'chunk_break_ms' => $configSnapshot['chunk_break_ms'] ?? null,
            'paragraph_break_ms' => $configSnapshot['paragraph_break_ms'] ?? null,
            'sentence_break_ms' => $configSnapshot['sentence_break_ms'] ?? null,
            'generation_version' => $this->generationVersion(),
        ];

        return hash('sha256', json_encode($signature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function localeForLesson(Lesson $lesson): string
    {
        return $this->ttsConfig->localeForLanguage($lesson->language ?: $lesson->target_language);
    }

    protected function buildAudioPath(Lesson $lesson, string $format): string
    {
        $directory = trim((string) config('lesson_generation.read_aloud.directory', 'lessons'), '/');
        $extension = $format === 'wav' ? 'wav' : 'mp3';

        return $directory . '/' . (int) $lesson->id . '/read-aloud/' . Str::uuid() . '.' . $extension;
    }
}
