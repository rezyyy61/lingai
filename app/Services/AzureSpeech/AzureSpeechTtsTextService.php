<?php

namespace App\Services\AzureSpeech;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AzureSpeechTtsTextService
{
    public function synthesizeSsml(string $ssml, string $outputFormat = 'audio-24khz-160kbitrate-mono-mp3'): string
    {
        $ssml = trim($ssml);

        $res = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token(),
            'Content-Type' => 'application/ssml+xml; charset=utf-8',
            'X-Microsoft-OutputFormat' => $outputFormat,
            'User-Agent' => $this->userAgent(),
        ])
            ->timeout((int) config('services.azure_speech.tts_timeout', 75))
            ->connectTimeout((int) config('services.azure_speech.tts_connect_timeout', 10))
            ->withBody($ssml, 'application/ssml+xml')
            ->post($this->ttsUrl());

        if (!$res->successful()) {
            $reqId = (string) ($res->header('X-Microsoft-RequestId') ?? $res->header('x-microsoft-requestid') ?? '');
            $body = trim((string) $res->body());
            $snippet = mb_substr($ssml, 0, 900);

            $msg = 'Azure TTS failed: ' . $res->status();
            if ($reqId !== '') $msg .= ' request_id=' . $reqId;
            if ($body !== '') $msg .= ' body=' . mb_substr($body, 0, 1200);
            $msg .= ' ssml_snippet=' . $snippet;

            throw new \RuntimeException($msg);
        }

        return (string) $res->body();
    }

    public function voicesList(): array
    {
        return Cache::remember('azure_speech_tts_voices_list', 3600, function () {
            $res = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->key(),
                'Accept' => 'application/json',
                'User-Agent' => $this->userAgent(),
            ])->timeout(25)->get($this->voicesUrl());

            if (! $res->successful()) {
                throw new \RuntimeException('Azure voices/list failed: ' . $res->status() . ' ' . mb_substr((string) $res->body(), 0, 800));
            }

            $json = $res->json();
            return is_array($json) ? $json : [];
        });
    }

    public function pickVoiceShortName(string $locale, string $gender = 'Female', ?string $preferStyle = 'chat'): ?string
    {
        $voices = $this->voicesList();

        $best = null;
        foreach ($voices as $v) {
            if (!is_array($v)) continue;

            $vLocale = (string) ($v['Locale'] ?? '');
            if (mb_strtolower($vLocale) !== mb_strtolower($locale)) continue;

            $short = (string) ($v['ShortName'] ?? '');
            if ($short === '') continue;

            $vGender = (string) ($v['Gender'] ?? '');
            if ($gender !== '' && mb_strtolower($vGender) !== mb_strtolower($gender)) continue;

            $status = (string) ($v['Status'] ?? '');
            if ($status !== '' && mb_strtolower($status) === 'deprecated') continue;

            $type = (string) ($v['VoiceType'] ?? '');
            if ($type !== '' && mb_strtolower($type) !== 'neural') continue;

            if ($preferStyle) {
                $styles = $v['StyleList'] ?? null;
                if (is_array($styles)) {
                    $ok = false;
                    foreach ($styles as $s) {
                        if (mb_strtolower((string) $s) === mb_strtolower($preferStyle)) {
                            $ok = true;
                            break;
                        }
                    }
                    if ($ok) return $short;
                }
            }

            $best = $best ?: $short;
        }

        if ($best) return $best;

        $fallback = [
            'en-us' => $gender === 'Male' ? 'en-US-GuyNeural' : 'en-US-JennyNeural',
            'nl-nl' => $gender === 'Male' ? 'nl-NL-MaartenNeural' : 'nl-NL-FennaNeural',
            'fa-ir' => 'fa-IR-FaridNeural',
        ];

        $k = mb_strtolower($locale);
        return $fallback[$k] ?? null;
    }

    protected function token(): string
    {
        return Cache::remember('azure_speech_token', 540, function () {
            $res = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->key(),
                'Content-Length' => '0',
            ])->post($this->tokenUrl());

            if (! $res->successful()) {
                throw new \RuntimeException('Azure Speech token failed: ' . $res->status() . ' ' . mb_substr((string) $res->body(), 0, 800));
            }

            return trim((string) $res->body());
        });
    }

    protected function tokenUrl(): string
    {
        $region = (string) config('services.azure_speech.region');
        return 'https://' . $region . '.api.cognitive.microsoft.com/sts/v1.0/issueToken';
    }

    protected function ttsUrl(): string
    {
        $region = (string) config('services.azure_speech.region');
        return 'https://' . $region . '.tts.speech.microsoft.com/cognitiveservices/v1';
    }

    protected function voicesUrl(): string
    {
        $region = (string) config('services.azure_speech.region');
        return 'https://' . $region . '.tts.speech.microsoft.com/cognitiveservices/voices/list';
    }

    protected function key(): string
    {
        $key = (string) config('services.azure_speech.key');
        if (trim($key) === '') {
            throw new \RuntimeException('AZURE_SPEECH_KEY is missing.');
        }
        return $key;
    }

    protected function userAgent(): string
    {
        $name = (string) config('app.name', 'App');
        $name = trim($name) !== '' ? trim($name) : 'App';
        return mb_substr($name, 0, 200);
    }
}
