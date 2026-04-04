<?php

namespace App\Services\AI;

use App\Services\AzureSpeech\AzureSpeechTtsTextService;
use Illuminate\Support\Str;

class AzureLessonTtsClient
{
    public function __construct(
        protected AzureSpeechTtsTextService $tts,
    ) {}

    public function synthesizeSegment(
        string $text,
        ?string $languageCode = null,
        ?string $speaker = null,
        ?string $style = null,
        string $format = 'wav'
    ): array
    {
        $text = $this->normalizeText($text);

        if ($text === '') {
            throw new \RuntimeException('Lesson spoken segment text is empty.');
        }

        $format = strtolower(trim($format));
        $format = $format === 'mp3' ? 'mp3' : 'wav';

        $locale = $this->toAzureLocale($languageCode);
        $voice = $this->voiceForSpeaker($speaker, $locale, $voice ?? null);
        $style = $this->normalizeStyle($style);

        $ssml = $this->buildSsml($text, $locale, $voice, $style);
        $outputFormat = $format === 'wav'
            ? 'riff-24khz-16bit-mono-pcm'
            : 'audio-24khz-160kbitrate-mono-mp3';

        return [
            'binary' => $this->tts->synthesizeSsml($ssml, $outputFormat),
            'voice' => $voice,
            'format' => $format,
            'locale' => $locale,
            'speaker' => $speaker ? trim($speaker) : null,
            'style' => $style,
        ];
    }

    protected function normalizeText(string $text): string
    {
        $text = html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public function voiceForSpeaker(?string $speaker, string $locale, ?string $fallbackVoice = null): string
    {
        $speaker = strtolower(trim((string) $speaker));
        $configured = config('lesson_generation.audio.speakers', []);
        $coachVoice = is_array($configured) ? trim((string) ($configured['coach'] ?? '')) : '';
        $helperVoice = is_array($configured) ? trim((string) ($configured['helper'] ?? '')) : '';

        return match ($speaker) {
            'coach' => $coachVoice !== '' ? $coachVoice : ($fallbackVoice ?: $this->defaultVoiceForLocale($locale)),
            'helper' => $helperVoice !== '' ? $helperVoice : ($coachVoice !== '' ? $coachVoice : ($fallbackVoice ?: $this->defaultVoiceForLocale($locale))),
            default => $fallbackVoice ?: ($coachVoice !== '' ? $coachVoice : $this->defaultVoiceForLocale($locale)),
        };
    }

    protected function buildSsml(string $text, string $locale, string $voice, ?string $style = null): string
    {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $styleOpen = $style ? '<mstts:express-as style="' . htmlspecialchars($style, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '">' : '';
        $styleClose = $style ? '</mstts:express-as>' : '';

        return '<speak version="1.0" xml:lang="' . $locale . '" xmlns="http://www.w3.org/2001/10/synthesis" xmlns:mstts="http://www.w3.org/2001/mstts">'
            . '<voice name="' . $voice . '">'
            . $styleOpen
            . '<prosody rate="0%">'
            . $escaped
            . '</prosody>'
            . $styleClose
            . '</voice>'
            . '</speak>';
    }

    protected function normalizeStyle(?string $style): ?string
    {
        if (! (bool) config('lesson_generation.audio.styles.use_segment_style', false)) {
            return null;
        }

        $style = strtolower(trim((string) $style));

        if ($style === '') {
            return null;
        }

        $allowed = config('lesson_generation.audio.styles.allowed', []);

        return is_array($allowed) && in_array($style, $allowed, true) ? $style : null;
    }

    protected function toAzureLocale(?string $languageCode): string
    {
        $code = Str::lower(trim((string) $languageCode));

        return match (true) {
            $code === 'nl' || str_starts_with($code, 'nl-') => 'nl-NL',
            $code === 'fa' || str_starts_with($code, 'fa-') => 'fa-IR',
            $code === 'fr' || str_starts_with($code, 'fr-') => 'fr-FR',
            $code === 'de' || str_starts_with($code, 'de-') => 'de-DE',
            $code === 'es' || str_starts_with($code, 'es-') => 'es-ES',
            $code === 'it' || str_starts_with($code, 'it-') => 'it-IT',
            $code === 'pt' || str_starts_with($code, 'pt-') => 'pt-PT',
            $code === 'ru' || str_starts_with($code, 'ru-') => 'ru-RU',
            $code === 'tr' || str_starts_with($code, 'tr-') => 'tr-TR',
            $code === 'ar' || str_starts_with($code, 'ar-') => 'ar-SA',
            $code === 'hi' || str_starts_with($code, 'hi-') => 'hi-IN',
            $code === 'ur' || str_starts_with($code, 'ur-') => 'ur-PK',
            $code === 'sv' || str_starts_with($code, 'sv-') => 'sv-SE',
            $code === 'no' || str_starts_with($code, 'no-') => 'nb-NO',
            $code === 'da' || str_starts_with($code, 'da-') => 'da-DK',
            $code === 'pl' || str_starts_with($code, 'pl-') => 'pl-PL',
            $code === 'ja' || str_starts_with($code, 'ja-') => 'ja-JP',
            $code === 'ko' || str_starts_with($code, 'ko-') => 'ko-KR',
            $code === 'zh' || str_starts_with($code, 'zh-') => 'zh-CN',
            default => 'en-US',
        };
    }

    protected function defaultVoiceForLocale(string $locale): string
    {
        return match ($locale) {
            'nl-NL' => 'nl-NL-FennaNeural',
            'fa-IR' => 'fa-IR-DilaraNeural',
            'fr-FR' => 'fr-FR-DeniseNeural',
            'de-DE' => 'de-DE-KatjaNeural',
            'es-ES' => 'es-ES-ElviraNeural',
            'it-IT' => 'it-IT-ElsaNeural',
            'pt-PT' => 'pt-PT-RaquelNeural',
            'ru-RU' => 'ru-RU-SvetlanaNeural',
            'tr-TR' => 'tr-TR-EmelNeural',
            'ar-SA' => 'ar-SA-ZariyahNeural',
            'hi-IN' => 'hi-IN-SwaraNeural',
            'ur-PK' => 'ur-PK-UzmaNeural',
            'sv-SE' => 'sv-SE-SofieNeural',
            'nb-NO' => 'nb-NO-PernilleNeural',
            'da-DK' => 'da-DK-ChristelNeural',
            'pl-PL' => 'pl-PL-ZofiaNeural',
            'ja-JP' => 'ja-JP-NanamiNeural',
            'ko-KR' => 'ko-KR-SunHiNeural',
            'zh-CN' => 'zh-CN-XiaoxiaoNeural',
            default => 'en-US-JennyNeural',
        };
    }
}
