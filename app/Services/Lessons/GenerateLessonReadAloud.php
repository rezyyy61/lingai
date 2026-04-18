<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Services\Audio\LessonAudioChunkMerger;
use App\Services\AzureSpeech\AzureSpeechTtsTextService;
use App\Services\Speech\ReadAloudSsmlBuilder;
use App\Services\Speech\TtsConfigResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateLessonReadAloud
{
    public function __construct(
        protected AzureSpeechTtsTextService $tts,
        protected ReadAloudTextChunker $chunker,
        protected ReadAloudSsmlBuilder $ssmlBuilder,
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
        $voice = $this->voiceForLocale($locale, $style);
        $chunkItems = $this->chunker->chunkWithMetadata($text);
        $chunks = array_map(static fn (array $chunk) => (string) $chunk['text'], $chunkItems);

        if ($chunks === []) {
            throw new RuntimeException('Lesson original text could not be chunked for read-aloud audio.');
        }

        $audioChunks = [];

        foreach ($chunkItems as $index => $chunkItem) {
            $chunk = (string) ($chunkItem['text'] ?? '');
            $ssml = $this->ssmlBuilder->build(
                text: $chunk,
                locale: $locale,
                voice: $voice,
                rate: $this->rate(),
                style: $style,
                sentenceBreakMs: $this->sentenceBreakMs(),
                paragraphBreakMs: $this->paragraphBreakMs(),
            );

            $audioChunks[] = [
                'binary' => $this->tts->synthesizeSsml($ssml, 'riff-24khz-16bit-mono-pcm'),
                'pause_ms' => $this->pauseAfterChunk($chunkItem, $index === array_key_last($chunkItems)),
            ];
        }

        $binary = $this->chunkMerger->merge($audioChunks, $format);
        $path = $this->buildAudioPath($lesson, $format);
        Storage::disk($disk)->put($path, $binary);
        $url = Storage::disk($disk)->url($path);

        DB::transaction(function () use ($lesson, $path, $url, $disk, $voice, $locale, $style, $format, $chunks, $chunkItems): void {
            /** @var Lesson $freshLesson */
            $freshLesson = Lesson::query()
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $meta = is_array($freshLesson->analysis_meta) ? $freshLesson->analysis_meta : [];
            data_set($meta, 'read_aloud.status', 'ready');
            data_set($meta, 'read_aloud.voice', $voice);
            data_set($meta, 'read_aloud.locale', $locale);
            data_set($meta, 'read_aloud.rate', $this->rate());
            data_set($meta, 'read_aloud.style', $this->style($locale));
            data_set($meta, 'read_aloud.format', $format);
            data_set($meta, 'read_aloud.generation_version', $this->generationVersion());
            data_set($meta, 'read_aloud.config_snapshot', $this->configSnapshot($locale, $voice, $style, $format));
            data_set($meta, 'read_aloud.generated_at', now()->toIso8601String());
            data_set($meta, 'read_aloud.chunk_count', count($chunks));
            data_set($meta, 'read_aloud.chunks', $this->timingChunks($chunkItems));
            data_forget($meta, 'read_aloud.sync_precision');
            data_forget($meta, 'read_aloud.alignment_provider');
            data_forget($meta, 'read_aloud.alignment_note');
            data_forget($meta, 'read_aloud.word_timestamps');
            data_forget($meta, 'read_aloud.sentence_timestamps');
            data_forget($meta, 'read_aloud.timings');
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

    protected function configSnapshot(string $locale, string $voice, ?string $style, string $format): array
    {
        return [
            ...$this->ttsConfig->configSnapshot(
                feature: 'lesson_read_aloud',
                locale: $locale,
                voice: $voice,
                style: $style,
                outputFormat: $format,
            ),
            'format' => $format,
            'chunk_max_chars' => (int) config('lesson_generation.read_aloud.chunk.max_chars', 1600),
            'chunk_break_ms' => $this->chunkBreakMs(),
            'paragraph_break_ms' => $this->paragraphBreakMs(),
            'sentence_break_ms' => $this->sentenceBreakMs(),
        ];
    }

    protected function voiceForLocale(string $locale, ?string $style): string
    {
        return $this->ttsConfig->voiceForLocale($locale, $style);
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
