<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Services\Audio\LessonAudioChunkMerger;
use App\Services\Speech\TextToSpeechManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateLessonAudio
{
    public function __construct(
        protected TextToSpeechManager $ttsManager,
        protected LessonAudioChunkMerger $chunkMerger,
        protected GenerateLessonAudioScript $generateLessonAudioScript,
    ) {}

    public function handle(Lesson $lesson): Lesson
    {
        if (! $lesson->hasSpokenAudioSegments()) {
            if (! $lesson->hasProcessableOriginalText()) {
                throw new RuntimeException('Lesson original text is required before generating lesson audio.');
            }

            $lesson = $this->generateLessonAudioScript->handle($lesson);
        }

        $spokenSegments = $lesson->spokenAudioSegments();

        if ($spokenSegments === []) {
            throw new RuntimeException('The generated lesson script did not contain spoken segments.');
        }

        $format = (string) config('lesson_generation.audio.format', 'mp3');
        $disk = (string) config('lesson_generation.audio.disk', 'public');
        $languageCode = $lesson->language ?: data_get($lesson->analysis_meta, 'audio_script.source_language_code');
        $voiceMap = [];
        $chunks = [];

        $tts = $this->ttsManager->providerFor('lesson_audio');

        foreach ($spokenSegments as $segment) {
            $audio = $tts->synthesizeLessonSegment(
                text: (string) $segment['text'],
                languageCode: is_string($languageCode) ? $languageCode : null,
                speaker: (string) $segment['speaker'],
                style: (string) $segment['style'],
                format: 'wav',
                options: [
                    'use_case' => 'lesson_audio',
                    'pacing_intent' => 'clear_natural_practice',
                ],
            );

            $voiceMap[(string) $segment['speaker']] = $audio['voice'];
            $chunks[] = [
                'binary' => $audio['binary'],
                'pause_ms' => (int) $segment['pause_ms'],
                'input_format' => (string) ($audio['input_format'] ?? 'wav'),
            ];
            $lastAudio = $audio;
        }

        if (! isset($lastAudio)) {
            throw new RuntimeException('No lesson audio chunks were generated.');
        }

        $binary = $this->chunkMerger->merge($chunks, $format);
        $primaryVoice = $voiceMap['coach'] ?? (array_values($voiceMap)[0] ?? $lastAudio['voice']);

        $path = $this->buildAudioPath($lesson, $format);
        Storage::disk($disk)->put($path, $binary);
        $url = Storage::disk($disk)->url($path);

        DB::transaction(function () use ($lesson, $path, $url, $format, $disk, $voiceMap, $lastAudio, $spokenSegments, $primaryVoice): void {
            /** @var Lesson $freshLesson */
            $freshLesson = Lesson::query()
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $meta = is_array($freshLesson->analysis_meta) ? $freshLesson->analysis_meta : [];
            data_set($meta, 'audio_generation.status', 'ready');
            data_set($meta, 'audio_generation.voice', $primaryVoice);
            data_set($meta, 'audio_generation.voice_map', $voiceMap);
            data_set($meta, 'audio_generation.provider', $lastAudio['provider']);
            data_set($meta, 'audio_generation.mapping_note', $lastAudio['mapping_note'] ?? null);
            data_set($meta, 'audio_generation.format', $format);
            data_set($meta, 'audio_generation.generated_at', now()->toIso8601String());
            data_set($meta, 'audio_generation.locale', $lastAudio['locale']);
            data_set($meta, 'audio_generation.disk', $disk);
            data_set($meta, 'audio_generation.path', $path);
            data_set($meta, 'audio_generation.segment_count', count($spokenSegments));

            $freshLesson->forceFill([
                'audio_path' => $path,
                'audio_url' => $url,
                'analysis_meta' => $meta,
            ])->save();
        });

        return $lesson->fresh();
    }

    protected function buildAudioPath(Lesson $lesson, string $format): string
    {
        $directory = trim((string) config('lesson_generation.audio.directory', 'lessons'), '/');
        $extension = $format === 'wav' ? 'wav' : 'mp3';

        return $directory . '/' . (int) $lesson->id . '/audio/' . Str::uuid() . '.' . $extension;
    }
}
