<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LessonWord;
use App\Services\AzureSpeech\AzureSpeechTtsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LessonWordTtsController extends Controller
{
    public function show(Request $request, LessonWord $word, AzureSpeechTtsService $tts)
    {
        if ($word->tts_audio_path && Storage::disk('public')->exists($word->tts_audio_path)) {
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

        $url = $tts->synthesizeShadowing($text, $language);

        $publicPath = parse_url($url, PHP_URL_PATH);
        $relative = ltrim(str_replace('/storage/', '', $publicPath), '/');

        $word->update([
            'tts_audio_path' => $relative,
        ]);

        return response()->json([
            'audio_url' => $url,
        ]);
    }
}
