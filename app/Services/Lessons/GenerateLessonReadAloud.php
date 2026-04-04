<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Services\Audio\LessonAudioChunkMerger;
use App\Services\AzureSpeech\AzureSpeechTtsTextService;
use App\Services\Speech\ReadAloudSsmlBuilder;
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
        $style = $this->style();
        $voice = $this->voiceForLocale($locale, $style);
        $chunks = $this->chunker->chunk($text);

        if ($chunks === []) {
            throw new RuntimeException('Lesson original text could not be chunked for read-aloud audio.');
        }

        $audioChunks = [];

        foreach ($chunks as $index => $chunk) {
            $ssml = $this->ssmlBuilder->build(
                text: $chunk,
                locale: $locale,
                voice: $voice,
                rate: $this->rate(),
                style: $style,
                sentenceBreakMs: $this->sentenceBreakMs(),
            );

            $audioChunks[] = [
                'binary' => $this->tts->synthesizeSsml($ssml, 'riff-24khz-16bit-mono-pcm'),
                'pause_ms' => $index === array_key_last($chunks) ? 0 : $this->chunkBreakMs(),
            ];
        }

        $binary = $this->chunkMerger->merge($audioChunks, $format);
        $path = $this->buildAudioPath($lesson, $format);
        Storage::disk($disk)->put($path, $binary);
        $url = Storage::disk($disk)->url($path);

        DB::transaction(function () use ($lesson, $path, $url, $disk, $voice, $locale, $format, $chunks): void {
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
            data_set($meta, 'read_aloud.style', $this->style());
            data_set($meta, 'read_aloud.format', $format);
            data_set($meta, 'read_aloud.generated_at', now()->toIso8601String());
            data_set($meta, 'read_aloud.chunk_count', count($chunks));
            data_set($meta, 'read_aloud.break_ms', $this->chunkBreakMs());
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

    protected function format(): string
    {
        $format = strtolower(trim((string) config('lesson_generation.read_aloud.format', 'mp3')));

        return $format === 'wav' ? 'wav' : 'mp3';
    }

    protected function rate(): string
    {
        $rate = trim((string) config('lesson_generation.read_aloud.rate', '0%'));

        return $rate !== '' ? $rate : '0%';
    }

    protected function style(): ?string
    {
        $style = trim((string) config('lesson_generation.read_aloud.style', ''));

        return $style !== '' ? $style : null;
    }

    protected function chunkBreakMs(): int
    {
        return max(0, (int) config('lesson_generation.read_aloud.break_ms', 420));
    }

    protected function sentenceBreakMs(): int
    {
        return max(0, (int) config('lesson_generation.read_aloud.sentence_break_ms', 180));
    }

    protected function voiceForLocale(string $locale, ?string $style): string
    {
        $configured = trim((string) config('lesson_generation.read_aloud.voice', ''));

        if ($configured !== '') {
            return $configured;
        }

        return $this->tts->pickVoiceShortName($locale, 'Female', $style)
            ?: $this->tts->pickVoiceShortName($locale, 'Female', null)
            ?: $this->tts->pickVoiceShortName($locale, 'Male', $style)
            ?: $this->tts->pickVoiceShortName($locale, 'Male', null)
            ?: throw new RuntimeException('No Azure Speech voice is available for read-aloud generation.');
    }

    protected function localeForLesson(Lesson $lesson): string
    {
        $languageCode = trim((string) ($lesson->language ?: $lesson->target_language ?: ''));
        $fallback = trim((string) config('lesson_generation.read_aloud.locale_fallback', 'en-US'));

        return match (true) {
            $languageCode === 'nl' || str_starts_with($languageCode, 'nl-') => 'nl-NL',
            $languageCode === 'fa' || str_starts_with($languageCode, 'fa-') => 'fa-IR',
            $languageCode === 'fr' || str_starts_with($languageCode, 'fr-') => 'fr-FR',
            $languageCode === 'de' || str_starts_with($languageCode, 'de-') => 'de-DE',
            $languageCode === 'es' || str_starts_with($languageCode, 'es-') => 'es-ES',
            $languageCode === 'it' || str_starts_with($languageCode, 'it-') => 'it-IT',
            $languageCode === 'pt' || str_starts_with($languageCode, 'pt-') => 'pt-PT',
            $languageCode === 'tr' || str_starts_with($languageCode, 'tr-') => 'tr-TR',
            $languageCode === 'ar' || str_starts_with($languageCode, 'ar-') => 'ar-SA',
            $languageCode === 'ja' || str_starts_with($languageCode, 'ja-') => 'ja-JP',
            $languageCode === 'ko' || str_starts_with($languageCode, 'ko-') => 'ko-KR',
            $languageCode === 'zh' || str_starts_with($languageCode, 'zh-') => 'zh-CN',
            $languageCode === 'en' || str_starts_with($languageCode, 'en-') => 'en-US',
            default => $fallback !== '' ? $fallback : 'en-US',
        };
    }

    protected function buildAudioPath(Lesson $lesson, string $format): string
    {
        $directory = trim((string) config('lesson_generation.read_aloud.directory', 'lessons'), '/');
        $extension = $format === 'wav' ? 'wav' : 'mp3';

        return $directory . '/' . (int) $lesson->id . '/read-aloud/' . Str::uuid() . '.' . $extension;
    }
}
