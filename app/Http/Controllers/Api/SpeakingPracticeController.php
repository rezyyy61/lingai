<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\LlmClient;
use App\Services\Audio\AudioConvertService;
use App\Services\AzureSpeech\AzureSpeechSttService;
use App\Services\AzureSpeech\AzureSpeechTtsService;
use Illuminate\Http\Request;

class SpeakingPracticeController extends Controller
{
    public function submit(Request $request, AzureSpeechSttService $stt, AzureSpeechTtsService $tts, AudioConvertService $conv, LlmClient $llm)
    {
        $request->validate([
            'audio' => ['required', 'file'],
            'target_language' => ['nullable', 'string'],
            'prompt' => ['nullable', 'string'],
        ]);

        $target = (string) ($request->input('target_language') ?: 'en');
        $prompt = (string) ($request->input('prompt') ?: '');

        $file = $request->file('audio');
        $inPath = $file->getRealPath();

        $wavPath = $conv->toWav16kMono($inPath);
        $wav = file_get_contents($wavPath) ?: '';

        @unlink($wavPath);

        $sttRes = $stt->transcribeWav($wav, $target);

        $spoken = (string) ($sttRes['text'] ?? '');
        if (trim($spoken) === '') {
            return response()->json([
                'message' => 'Could not recognize speech.',
                'stt' => $sttRes,
            ], 422);
        }

        $feedback = $this->getSpeakingFeedback($llm, $spoken, $target, $prompt);

        $ttsText = (string) ($feedback['corrected'] ?? $spoken);
        $audioUrl = $tts->synthesizeShadowing($ttsText, $target, null, 'slow');

        return response()->json([
            'spoken' => $spoken,
            'confidence' => $sttRes['confidence'] ?? null,
            'feedback' => $feedback,
            'audio_url' => $audioUrl,
        ]);
    }

    protected function getSpeakingFeedback(LlmClient $llm, string $spoken, string $target, string $prompt): array
    {
        $messages = [
            ['role' => 'system', 'content' => 'Return ONLY valid JSON. No markdown. Schema: {"corrected":"","notes":[],"score":0,"suggested_answer":""}'],
            ['role' => 'user', 'content' =>
                "Target language: {$target}\n" .
                "Exercise prompt/context (optional): {$prompt}\n" .
                "User spoke: {$spoken}\n" .
                "Task: Correct the sentence for natural {$target}. Provide 1-4 short notes. Score 0-100 for clarity+grammar+naturalness. Suggest a natural answer if relevant."
            ],
        ];

        $res = $llm->chatJson($messages, [
            'max_output_tokens' => 500,
            'temperature' => 0.2,
        ]);

        return is_array($res) ? $res : [
            'corrected' => $spoken,
            'notes' => [],
            'score' => 0,
            'suggested_answer' => '',
        ];
    }
}
