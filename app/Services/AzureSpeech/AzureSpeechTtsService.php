<?php

namespace App\Services\AzureSpeech;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AzureSpeechTtsService
{
    public function synthesizeShadowing(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
        string $speed = 'slow'
    ): string {
        $text = $this->prepareShadowingText($text);

        if ($text === '') {
            throw new \InvalidArgumentException('Text is empty.');
        }

        $locale = $this->toAzureLocale($languageCode);
        $voice = $voice ?? $this->defaultVoiceForLocale($locale);

        $rate = $this->rateForSpeed($speed);
        $pitch = $this->pitchForSpeed($speed);
        $style = $this->styleForShadowing($locale);

        $ssml = $this->buildShadowingSsml($text, $locale, $voice, $rate, $pitch, $style);

        try {
            $binary = $this->requestTts($ssml);
        } catch (\Throwable $e) {
            $ssml2 = $this->buildShadowingSsml($text, $locale, $voice, $rate, '0%', null);
            $binary = $this->requestTts($ssml2);
        }

        $path = 'lesson_tts/' . Str::uuid() . '.mp3';
        Storage::disk('public')->put($path, $binary);

        return Storage::disk('public')->url($path);
    }


    protected function requestTts(string $ssml): string
    {
        $ttsUrl = $this->ttsUrl();
        $token = $this->token();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/ssml+xml',
            'X-Microsoft-OutputFormat' => 'audio-24khz-48kbitrate-mono-mp3',
            'User-Agent' => 'zeel-tts',
        ])->withBody($ssml, 'application/ssml+xml')->post($ttsUrl);

        if (! $response->successful()) {
            Log::error('Azure TTS failed', [
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'body_head' => mb_substr((string) $response->body(), 0, 2000),
                'ssml_head' => mb_substr($ssml, 0, 1200),
            ]);

            throw new \RuntimeException('Azure TTS failed: ' . $response->status() . ' ' . mb_substr((string) $response->body(), 0, 800));
        }

        $ct = (string) $response->header('Content-Type');
        $bin = $response->body();

        if (mb_strlen($bin) < 200) {
            Log::error('Azure TTS returned too small body', [
                'status' => $response->status(),
                'content_type' => $ct,
                'len' => mb_strlen($bin),
                'body_head' => mb_substr((string) $response->body(), 0, 2000),
                'ssml_head' => mb_substr($ssml, 0, 1200),
            ]);

            throw new \RuntimeException('Azure TTS returned empty/invalid audio.');
        }

        return $bin;
    }


    protected function token(): string
    {
        return Cache::remember('azure_speech_token', 540, function () {
            $response = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->key(),
                'Content-Length' => '0',
            ])->post($this->tokenUrl());

            if (! $response->successful()) {
                throw new \RuntimeException('Azure Speech token failed: ' . $response->status() . ' ' . $response->body());
            }

            return trim($response->body());
        });
    }

    protected function buildSsml(string $text, string $locale, string $voice, string $rate): string
    {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<speak version="1.0" xml:lang="' . $locale . '" xmlns="http://www.w3.org/2001/10/synthesis">'
            . '<voice name="' . $voice . '">'
            . '<prosody rate="' . $rate . '">'
            . $escaped
            . '</prosody>'
            . '</voice>'
            . '</speak>';
    }

    protected function rateForSpeed(string $speed): string
    {
        return match ($speed) {
            'slow' => '-12%',
            'normal' => '0%',
            'fast' => '+8%',
            default => '0%',
        };
    }

    protected function toAzureLocale(?string $languageCode): string
    {
        $code = strtolower(trim((string) $languageCode));

        if ($code === '') {
            return 'en-US';
        }

        if (str_starts_with($code, 'en')) return 'en-US';
        if (str_starts_with($code, 'nl')) return 'nl-NL';
        if (str_starts_with($code, 'fa')) return 'fa-IR';

        return 'en-US';
    }

    protected function defaultVoiceForLocale(string $locale): string
    {
        return match (true) {
            str_starts_with($locale, 'en-') => 'en-US-JennyNeural',
            str_starts_with($locale, 'nl-') => 'nl-NL-ColetteNeural',
            str_starts_with($locale, 'fa-') => 'fa-IR-DilaraNeural',
            default => 'en-US-JennyNeural',
        };
    }

    protected function tokenUrl(): string
    {
        $region = config('services.azure_speech.region');
        return 'https://' . $region . '.api.cognitive.microsoft.com/sts/v1.0/issueToken';
    }

    protected function ttsUrl(): string
    {
        $region = config('services.azure_speech.region');
        return 'https://' . $region . '.tts.speech.microsoft.com/cognitiveservices/v1';
    }

    protected function key(): string
    {
        $key = config('services.azure_speech.key');

        if (! $key) {
            throw new \RuntimeException('AZURE_SPEECH_KEY is missing.');
        }

        return $key;
    }

    protected function buildShadowingSsml(string $text, string $locale, string $voice, string $rate, string $pitch, ?string $style): string
    {
        $escaped = $this->escapeSsml($text);

        $styleOpen = '';
        $styleClose = '';

        if ($style) {
            $styleOpen = '<mstts:express-as style="' . $style . '">';
            $styleClose = '</mstts:express-as>';
        }

        return '<speak version="1.0" xml:lang="' . $locale . '" xmlns="http://www.w3.org/2001/10/synthesis" xmlns:mstts="http://www.w3.org/2001/mstts">'
            . '<voice name="' . $voice . '">'
            . $styleOpen
            . '<prosody rate="' . $rate . '" pitch="' . $pitch . '">'
            . $escaped
            . '</prosody>'
            . $styleClose
            . '</voice>'
            . '</speak>';
    }

    protected function prepareShadowingText(string $text): string
    {
        $t = trim((string) $text);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim($t);

        if ($t === '') {
            return '';
        }

        $t = preg_replace('/\s*([,.!?;:])\s*/u', '$1 ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        $t = preg_replace('/([!?\.])\s+/u', '$1 <break time="280ms"/> ', $t) ?? $t;
        $t = preg_replace('/,\s+/u', ', <break time="140ms"/> ', $t) ?? $t;

        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    protected function escapeSsml(string $text): string
    {
        $placeholderPrefix = '__SSML_TAG_' . Str::uuid() . '_';
        $tags = [];

        $i = 0;
        $text = preg_replace_callback('/<break\s+time="[^"]+"\s*\/>/i', function ($m) use (&$tags, &$i, $placeholderPrefix) {
            $key = $placeholderPrefix . $i . '__';
            $tags[$key] = $m[0];
            $i++;
            return $key;
        }, $text) ?? $text;

        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        foreach ($tags as $key => $tag) {
            $escaped = str_replace($key, $tag, $escaped);
        }

        return $escaped;
    }

    protected function pitchForSpeed(string $speed): string
    {
        return match ($speed) {
            'slow' => '-2%',
            'normal' => '0%',
            'fast' => '+1%',
            default => '0%',
        };
    }

    protected function styleForShadowing(string $locale): ?string
    {
        if (str_starts_with($locale, 'en-')) {
            return 'narration-professional';
        }

        return null;
    }

}
