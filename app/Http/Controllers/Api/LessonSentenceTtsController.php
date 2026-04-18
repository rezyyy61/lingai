<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LessonSentence;
use App\Services\AzureSpeech\AzureSpeechTtsService;
use App\Services\Speech\TtsConfigResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LessonSentenceTtsController extends Controller
{
    public function show(Request $request, LessonSentence $sentence, AzureSpeechTtsService $tts, TtsConfigResolver $ttsConfig)
    {
        $preset = trim($request->string('preset')->toString());
        $cachedPreset = trim((string) data_get($sentence->meta, 'shadowing_tts.preset'));
        $cachedVersion = (string) data_get($sentence->meta, 'shadowing_tts.generation_version');

        if (
            $sentence->tts_audio_path
            && Storage::disk('public')->exists($sentence->tts_audio_path)
            && $cachedVersion === $ttsConfig->generationVersion()
            && ($preset === '' || $preset === $cachedPreset)
        ) {
            return response()->json([
                'audio_url' => $sentence->tts_audio_url,
            ]);
        }

        $text = $sentence->text;

        if (! $text) {
            return response()->json([
                'message' => 'Sentence has no text.',
            ], 422);
        }

        $language = optional($sentence->lesson)->target_language
            ?? config('learning_languages.default_target', 'en');

        $result = $tts->synthesizeShadowingDetailed(
            text: $text,
            languageCode: $language,
            voice: null,
            speed: 'slow',
            preset: $preset !== '' ? $preset : null,
            feature: 'practice_shadowing',
        );

        $meta = is_array($sentence->meta) ? $sentence->meta : [];
        data_set($meta, 'shadowing_tts', [
            'preset' => $result['preset'],
            'voice' => $result['voice'],
            'locale' => $result['locale'],
            'style' => $result['style'],
            'disk' => $result['disk'],
            'path' => $result['path'],
            'output_format' => $result['output_format'],
            'generation_version' => $result['generation_version'],
            'config_snapshot' => $result['config_snapshot'],
            'generated_at' => $result['generated_at'],
            'sequence' => $result['sequence'],
        ]);

        $sentence->update([
            'tts_audio_path' => $result['path'],
            'meta' => $meta,
        ]);

        return response()->json([
            'audio_url' => $result['url'],
        ]);
    }
}
