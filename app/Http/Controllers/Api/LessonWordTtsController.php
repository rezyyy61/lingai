<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LessonWord;
use App\Services\Speech\TextToSpeechManager;
use App\Services\Speech\TtsConfigResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LessonWordTtsController extends Controller
{
    public function show(Request $request, LessonWord $word, TextToSpeechManager $ttsManager, TtsConfigResolver $ttsConfig)
    {
        if (
            $word->tts_audio_path
            && Storage::disk('public')->exists($word->tts_audio_path)
            && data_get($word->meta, 'flashcard_tts.generation_version') === $ttsConfig->generationVersion()
        ) {
            return response()->json([
                'audio_url' => $word->tts_audio_url,
            ]);
        }

        $text = $word->term;

        if (! $text) {
            return response()->json([
                'message' => 'Word has no term.',
            ], 422);
        }

        $language = optional($word->lesson)->target_language
            ?? config('learning_languages.default_target', 'en');

        $result = $ttsManager->providerFor('practice_flashcard')->synthesizeShadowingDetailed(
            text: $text,
            languageCode: $language,
            voice: null,
            speed: 'slow',
            preset: null,
            feature: 'practice_flashcard',
        );

        $meta = is_array($word->meta) ? $word->meta : [];
        data_set($meta, 'flashcard_tts', [
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

        $word->update([
            'tts_audio_path' => $result['path'],
            'meta' => $meta,
        ]);

        return response()->json([
            'audio_url' => $result['url'],
        ]);
    }
}
