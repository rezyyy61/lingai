<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LessonSentence;
use App\Services\OpenAI\OpenAiAudioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LessonSentenceTtsController extends Controller
{
    public function show(Request $request, LessonSentence $sentence, OpenAiAudioService $tts)
    {
        if ($sentence->tts_audio_path && Storage::disk('public')->exists($sentence->tts_audio_path)) {
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

        $url = $tts->synthesizeSpeech($text, $language);

        $publicPath = parse_url($url, PHP_URL_PATH);
        $relative = ltrim(str_replace('/storage/', '', $publicPath), '/');

        $sentence->update([
            'tts_audio_path' => $relative,
        ]);

        return response()->json([
            'audio_url' => $url,
        ]);
    }
}
