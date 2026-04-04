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
        string $speed = 'slow',
        ?string $preset = null,
    ): string {
        return $this->synthesizeShadowingDetailed($text, $languageCode, $voice, $speed, $preset)['url'];
    }

    public function synthesizeShadowingDetailed(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
        string $speed = 'slow',
        ?string $preset = null,
    ): array {
        $text = $this->prepareShadowingText($text);

        if ($text === '') {
            throw new \InvalidArgumentException('Text is empty.');
        }

        $presetConfig = $this->shadowingPreset($preset, $speed);
        $locale = $this->toAzureLocale($languageCode);
        $voice = $voice ?? $this->defaultVoiceForLocale($locale);
        $style = $this->styleForShadowing($locale);
        $outputFormat = (string) ($presetConfig['output_format'] ?? config('lesson_generation.shadowing_tts.output_format', 'audio-24khz-48kbitrate-mono-mp3'));
        $disk = (string) config('lesson_generation.shadowing_tts.disk', 'public');

        $ssml = $this->buildShadowingSsml($text, $locale, $voice, $presetConfig, $style, true);

        try {
            $binary = $this->requestTts($ssml, $outputFormat);
        } catch (\Throwable $e) {
            Log::warning('Azure shadowing TTS styled SSML failed, retrying with simpler SSML', [
                'locale' => $locale,
                'voice' => $voice,
                'preset' => $presetConfig['name'],
                'style' => $style,
                'message' => $e->getMessage(),
            ]);

            $ssml = $this->buildShadowingSsml($text, $locale, $voice, $presetConfig, null, false);
            $binary = $this->requestTts($ssml, $outputFormat);
        }

        $path = $this->buildStoragePath($outputFormat);
        Storage::disk($disk)->put($path, $binary);

        return [
            'path' => $path,
            'url' => Storage::disk($disk)->url($path),
            'disk' => $disk,
            'voice' => $voice,
            'locale' => $locale,
            'style' => $style,
            'preset' => (string) $presetConfig['name'],
            'output_format' => $outputFormat,
            'generated_at' => now()->toIso8601String(),
            'sequence' => [
                'first_pass_rate' => (string) $presetConfig['first_pass_rate'],
                'second_pass_rate' => (string) $presetConfig['second_pass_rate'],
                'final_pass_rate' => (string) $presetConfig['final_pass_rate'],
                'between_first_and_second_pause_ms' => (int) $presetConfig['between_first_and_second_pause_ms'],
                'repeat_pause_ms' => (int) $presetConfig['repeat_pause_ms'],
                'final_tail_pause_ms' => (int) $presetConfig['final_tail_pause_ms'],
            ],
        ];
    }


    protected function requestTts(string $ssml, string $outputFormat): string
    {
        $ttsUrl = $this->ttsUrl();
        $token = $this->token();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/ssml+xml',
            'X-Microsoft-OutputFormat' => $outputFormat,
            'User-Agent' => 'zeel-tts',
        ])
            ->timeout((int) config('services.azure_speech.tts_timeout', 75))
            ->connectTimeout((int) config('services.azure_speech.tts_connect_timeout', 10))
            ->withBody($ssml, 'application/ssml+xml')
            ->post($ttsUrl);

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
            ])
                ->timeout((int) config('services.azure_speech.tts_timeout', 75))
                ->connectTimeout((int) config('services.azure_speech.tts_connect_timeout', 10))
                ->post($this->tokenUrl());

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

    protected function toAzureLocale(?string $languageCode): string
    {
        $code = strtolower(trim((string) $languageCode));
        $map = (array) config('lesson_generation.shadowing_tts.locale_map', []);

        if ($code === '') {
            return 'en-US';
        }

        foreach ($map as $prefix => $locale) {
            if (str_starts_with($code, strtolower((string) $prefix))) {
                return (string) $locale;
            }
        }

        return 'en-US';
    }

    protected function defaultVoiceForLocale(string $locale): string
    {
        $map = (array) config('lesson_generation.shadowing_tts.voice_map', []);

        if (is_string($map[$locale] ?? null) && trim((string) $map[$locale]) !== '') {
            return trim((string) $map[$locale]);
        }

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

    protected function buildShadowingSsml(string $text, string $locale, string $voice, array $preset, ?string $style, bool $allowExpressiveMarkup): string
    {
        $styleOpen = '';
        $styleClose = '';

        if ($style) {
            $styleOpen = '<mstts:express-as style="' . $style . '">';
            $styleClose = '</mstts:express-as>';
        }

        $passes = [
            $this->shadowingPassMarkup(
                text: $text,
                rate: (string) $preset['first_pass_rate'],
                pitch: (string) ($preset['first_pass_pitch'] ?? '0%'),
                emphasisLevel: null,
                allowExpressiveMarkup: $allowExpressiveMarkup,
            ),
            '<break time="' . (int) $preset['between_first_and_second_pause_ms'] . 'ms"/>',
            $this->shadowingPassMarkup(
                text: $text,
                rate: (string) $preset['second_pass_rate'],
                pitch: (string) ($preset['second_pass_pitch'] ?? '0%'),
                emphasisLevel: null,
                allowExpressiveMarkup: $allowExpressiveMarkup,
            ),
            '<break time="' . (int) $preset['repeat_pause_ms'] . 'ms"/>',
            $this->shadowingPassMarkup(
                text: $text,
                rate: (string) $preset['final_pass_rate'],
                pitch: (string) ($preset['final_pass_pitch'] ?? '0%'),
                emphasisLevel: $this->finalPassEmphasisLevel($text, $preset, $allowExpressiveMarkup),
                allowExpressiveMarkup: $allowExpressiveMarkup,
            ),
        ];

        $tailPause = (int) ($preset['final_tail_pause_ms'] ?? 0);
        if ($tailPause > 0) {
            $passes[] = '<break time="' . $tailPause . 'ms"/>';
        }

        return '<speak version="1.0" xml:lang="' . $locale . '" xmlns="http://www.w3.org/2001/10/synthesis" xmlns:mstts="http://www.w3.org/2001/mstts">'
            . '<voice name="' . $voice . '">'
            . $styleOpen
            . implode('', $passes)
            . $styleClose
            . '</voice>'
            . '</speak>';
    }

    protected function prepareShadowingText(string $text): string
    {
        $t = trim((string) $text);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace(["\r\n", "\r"], ' ', $t);
        $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s*([,.!?;:])\s*/u', '$1 ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim($t);

        if ($t === '') {
            return '';
        }

        $t = $this->stripBalancedWrappingQuotes($t);

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

    protected function styleForShadowing(string $locale): ?string
    {
        if (str_starts_with($locale, 'en-')) {
            return 'narration-professional';
        }

        return null;
    }

    protected function shadowingPreset(?string $preset, string $speed): array
    {
        $presets = (array) config('lesson_generation.shadowing_tts.presets', []);
        $defaultName = (string) config('lesson_generation.shadowing_tts.default_preset', 'standard');
        $requested = trim((string) $preset);
        $name = $requested !== '' ? $requested : $this->defaultPresetNameForSpeed($speed);
        $selected = $presets[$name] ?? $presets[$defaultName] ?? $presets['standard'] ?? [];

        return array_merge([
            'name' => $name,
            'first_pass_rate' => '0%',
            'second_pass_rate' => '-12%',
            'final_pass_rate' => '0%',
            'first_pass_pitch' => '0%',
            'second_pass_pitch' => '0%',
            'final_pass_pitch' => '0%',
            'between_first_and_second_pause_ms' => 420,
            'repeat_pause_ms' => 1550,
            'final_tail_pause_ms' => 220,
            'emphasis_level' => 'moderate',
            'output_format' => (string) config('lesson_generation.shadowing_tts.output_format', 'audio-24khz-48kbitrate-mono-mp3'),
        ], $selected, [
            'name' => array_key_exists($name, $presets) ? $name : ($defaultName !== '' ? $defaultName : 'standard'),
        ]);
    }

    protected function defaultPresetNameForSpeed(string $speed): string
    {
        return match ($speed) {
            'slow' => 'beginner',
            'fast' => 'intensive',
            default => 'standard',
        };
    }

    protected function shadowingPassMarkup(string $text, string $rate, string $pitch, ?string $emphasisLevel, bool $allowExpressiveMarkup): string
    {
        $spoken = $this->escapeSsml($text);

        if ($allowExpressiveMarkup && $emphasisLevel !== null) {
            $spoken = '<emphasis level="' . htmlspecialchars($emphasisLevel, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '">' . $spoken . '</emphasis>';
        }

        return '<prosody rate="' . htmlspecialchars($rate, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" pitch="' . htmlspecialchars($pitch, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '">'
            . $spoken
            . '</prosody>';
    }

    protected function finalPassEmphasisLevel(string $text, array $preset, bool $allowExpressiveMarkup): ?string
    {
        if (! $allowExpressiveMarkup) {
            return null;
        }

        if (preg_match('/[!?]$/u', $text) !== 1 && $this->wordCount($text) > 12) {
            return null;
        }

        return trim((string) ($preset['emphasis_level'] ?? '')) ?: null;
    }

    protected function stripBalancedWrappingQuotes(string $text): string
    {
        $pairs = [
            ['"', '"'],
            ["'", "'"],
            ['“', '”'],
            ['‘', '’'],
            ['«', '»'],
        ];

        foreach ($pairs as [$open, $close]) {
            if (str_starts_with($text, $open) && str_ends_with($text, $close)) {
                return trim(mb_substr($text, mb_strlen($open), mb_strlen($text) - mb_strlen($open) - mb_strlen($close)));
            }
        }

        return $text;
    }

    protected function buildStoragePath(string $outputFormat): string
    {
        $directory = trim((string) config('lesson_generation.shadowing_tts.directory', 'lesson_tts'), '/');

        return $directory . '/' . Str::uuid() . '.' . $this->extensionForOutputFormat($outputFormat);
    }

    protected function extensionForOutputFormat(string $outputFormat): string
    {
        $format = strtolower($outputFormat);

        if (str_contains($format, 'wav') || str_contains($format, 'riff')) {
            return 'wav';
        }

        return 'mp3';
    }

    protected function wordCount(string $text): int
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $text);

        return is_array($parts) ? count($parts) : 0;
    }

}
