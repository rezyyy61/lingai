<?php

namespace App\Services\Speech;

use App\Services\AzureSpeech\AzureSpeechTtsService;
use App\Services\AzureSpeech\AzureSpeechTtsTextService;
use App\Services\Speech\Contracts\TextToSpeechInterface;
use RuntimeException;

class AzureTextToSpeechService implements TextToSpeechInterface
{
    public function __construct(
        protected AzureSpeechTtsService $shadowing,
        protected AzureSpeechTtsTextService $ttsText,
        protected ReadAloudSsmlBuilder $readAloudSsmlBuilder,
        protected TtsConfigResolver $ttsConfig,
    ) {}

    public function providerName(): string
    {
        return 'azure';
    }

    public function supportsFeature(string $feature): bool
    {
        return in_array($feature, [
            'practice_shadowing',
            'practice_flashcard',
            'speaking_practice',
            'lesson_audio',
            'lesson_read_aloud',
        ], true);
    }

    public function synthesizeShadowing(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
        string $speed = 'slow',
        ?string $preset = null,
        string $feature = 'practice_shadowing',
    ): string {
        return $this->shadowing->synthesizeShadowing(
            text: $text,
            languageCode: $languageCode,
            voice: $voice,
            speed: $speed,
            preset: $preset,
            feature: $feature,
        );
    }

    public function synthesizeShadowingDetailed(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
        string $speed = 'slow',
        ?string $preset = null,
        string $feature = 'practice_shadowing',
    ): array {
        return $this->shadowing->synthesizeShadowingDetailed(
            text: $text,
            languageCode: $languageCode,
            voice: $voice,
            speed: $speed,
            preset: $preset,
            feature: $feature,
        );
    }

    public function synthesizeLessonSegment(
        string $text,
        ?string $languageCode = null,
        ?string $speaker = null,
        ?string $style = null,
        string $format = 'wav',
        array $options = [],
    ): array {
        $text = $this->normalizeText($text);

        if ($text === '') {
            throw new RuntimeException('Lesson spoken segment text is empty.');
        }

        $format = strtolower(trim($format)) === 'mp3' ? 'mp3' : 'wav';
        $locale = $this->ttsConfig->localeForLanguage($languageCode);
        $style = $this->normalizeSegmentStyle($style);
        $voice = $this->voiceForSpeaker($speaker, $locale);
        $outputFormat = $format === 'wav'
            ? 'riff-24khz-16bit-mono-pcm'
            : 'audio-24khz-160kbitrate-mono-mp3';

        $binary = $this->ttsText->synthesizeSsml(
            $this->buildLessonSegmentSsml($text, $locale, $voice, $style),
            $outputFormat
        );

        return [
            'binary' => $this->assertAudioBinary($binary, 'Azure lesson audio'),
            'voice' => $voice,
            'format' => $format,
            'input_format' => $outputFormat,
            'locale' => $locale,
            'speaker' => $speaker ? trim($speaker) : null,
            'style' => $style,
            'provider' => $this->providerName(),
            'mapping_note' => 'Azure lesson audio uses Azure SSML prosody and optional expressive style tags.',
        ];
    }

    public function synthesizeReadAloudText(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
        string $format = 'wav',
        array $options = [],
    ): array {
        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException('Read-aloud text is empty.');
        }

        $locale = $this->ttsConfig->localeForLanguage($languageCode);
        $style = array_key_exists('style', $options)
            ? $this->normalizeNullableString($options['style'])
            : $this->ttsConfig->styleForLocale($locale);
        $rate = $this->normalizeNullableString($options['rate']) ?? $this->ttsConfig->rate();
        $voice = $this->ttsConfig->voiceForLocaleUsingProvider($locale, $style, $voice, $this->providerName());
        $outputFormat = $this->normalizeNullableString($options['output_format'])
            ?? ($format === 'mp3' ? 'audio-24khz-160kbitrate-mono-mp3' : 'riff-24khz-16bit-mono-pcm');

        $binary = $this->ttsText->synthesizeSsml(
            $this->readAloudSsmlBuilder->build(
                text: $text,
                locale: $locale,
                voice: $voice,
                rate: $rate,
                style: $style,
                sentenceBreakMs: max(0, (int) ($options['sentence_break_ms'] ?? 180)),
                paragraphBreakMs: max(0, (int) ($options['paragraph_break_ms'] ?? 520)),
            ),
            $outputFormat
        );

        return [
            'binary' => $this->assertAudioBinary($binary, 'Azure read-aloud audio'),
            'voice' => $voice,
            'format' => $format,
            'input_format' => $outputFormat,
            'locale' => $locale,
            'style' => $style,
            'provider' => $this->providerName(),
            'output_format' => $outputFormat,
            'mapping_note' => 'Azure read-aloud uses SSML sentence and paragraph pauses inside each synthesis request.',
        ];
    }

    protected function voiceForSpeaker(?string $speaker, string $locale): string
    {
        $speaker = strtolower(trim((string) $speaker));
        $configured = config('lesson_generation.audio.speakers', []);
        $coachVoice = is_array($configured) ? trim((string) ($configured['coach'] ?? '')) : '';
        $helperVoice = is_array($configured) ? trim((string) ($configured['helper'] ?? '')) : '';
        $fallback = $this->ttsConfig->voiceForLocaleUsingProvider($locale, null, null, $this->providerName());

        return match ($speaker) {
            'coach' => $coachVoice !== '' ? $coachVoice : $fallback,
            'helper' => $helperVoice !== '' ? $helperVoice : ($coachVoice !== '' ? $coachVoice : $fallback),
            default => $coachVoice !== '' ? $coachVoice : $fallback,
        };
    }

    protected function buildLessonSegmentSsml(string $text, string $locale, string $voice, ?string $style): string
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

    protected function normalizeText(string $text): string
    {
        $text = html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    protected function normalizeSegmentStyle(?string $style): ?string
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

    protected function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function assertAudioBinary(string $binary, string $context): string
    {
        if (mb_strlen($binary) < 200) {
            throw new RuntimeException($context . ' is empty or invalid.');
        }

        return $binary;
    }
}
