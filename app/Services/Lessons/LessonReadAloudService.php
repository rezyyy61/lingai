<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Services\AzureSpeech\AzureSpeechTtsTextService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LessonReadAloudService
{
    public function __construct(
        protected AzureSpeechTtsTextService $tts,
        protected LessonSsmlBuilder $ssml,
    ) {}

    public function generate(
        Lesson $lesson,
        string $speed = 'normal',
        string $format = 'mp3',
        string $mode = 'auto',
        string $voicePair = 'auto'
    ): array {
        $speed = in_array($speed, ['slow', 'normal', 'fast'], true) ? $speed : 'normal';
        $format = in_array($format, ['mp3', 'wav'], true) ? $format : 'mp3';
        $mode = $this->normalizeMode($mode);

        $outputFormat = match ($format) {
            'wav' => 'riff-24khz-16bit-mono-pcm',
            default => 'audio-24khz-160kbitrate-mono-mp3',
        };

        $ext = $format === 'wav' ? 'wav' : 'mp3';

        $storyText = trim((string) ($lesson->original_text ?? ''));
        $locale = $this->toAzureLocale((string) ($lesson->target_language ?? 'en'));

        $meta = (array) ($lesson->analysis_meta ?? []);
        $dialogueRows = data_get($meta, 'lesson_pack.dialogue', []);
        if (!is_array($dialogueRows)) $dialogueRows = [];

        $segments = $this->buildStoryThenDialogueSegments($storyText, $dialogueRows);

        if (count($segments) === 0) {
            return [
                'exists' => false,
                'parts' => [],
                'speed' => $speed,
                'format' => $format,
                'mode' => $mode,
                'voice_pair' => $voicePair,
                'voice' => null,
                'voices' => null,
                'locale' => $locale,
                'base_url' => null,
                'generated_at' => null,
                'speaker_map' => [],
            ];
        }

        $voices = $this->pickVoices($locale, $voicePair);
        if (!is_string($voices['narrator'] ?? null) || $voices['narrator'] === '') {
            throw new \RuntimeException('No Azure voice found for locale: ' . $locale);
        }

        $hasDialogue = $this->looksLikeDialogue($segments);

        $effectiveMode = $mode === 'auto'
            ? ($hasDialogue ? 'mixed' : 'narration')
            : $mode;

        $voiceRunsPack = $this->buildVoiceRuns(
            speakerSegments: $segments,
            voices: $voices,
            mode: $effectiveMode,
            maxCharsPerRun: 2200
        );

        $voiceRuns = $voiceRunsPack['runs'];
        $speakerMap = $voiceRunsPack['speaker_map'];

        if (count($voiceRuns) === 0) {
            return [
                'exists' => false,
                'parts' => [],
                'speed' => $speed,
                'format' => $format,
                'mode' => $effectiveMode,
                'voice_pair' => $voicePair,
                'voice' => $voices['narrator'],
                'voices' => $voices,
                'locale' => $locale,
                'base_url' => null,
                'generated_at' => null,
                'speaker_map' => $speakerMap,
            ];
        }

        $disk = 'public';
        $uuid = (string) Str::uuid();
        $basePath = 'lessons/' . (int) $lesson->id . '/read_aloud/' . $speed . '/' . $format . '/' . $uuid;

        $parts = [];
        foreach ($voiceRuns as $i => $run) {
            $voiceName = (string) ($run['voice'] ?? '');
            $segmentsText = (array) ($run['segments'] ?? []);
            if ($voiceName === '' || empty($segmentsText)) {
                continue;
            }

            $ssml = $this->buildSingleVoiceSsml(
                segments: $segmentsText,
                locale: $locale,
                voiceName: $voiceName,
                speed: $speed
            );

            $audioBinary = $this->tts->synthesizeSsml($ssml, $outputFormat);

            $path = $basePath . '/' . ($i + 1) . '.' . $ext;
            Storage::disk($disk)->put($path, $audioBinary);

            $parts[] = [
                'index' => $i + 1,
                'path' => $path,
                'url' => '/storage/' . ltrim($path, '/'),
                'chars' => $this->charsCount($segmentsText),
                'voice' => $voiceName,
            ];
        }

        $baseUrl = '/storage/' . ltrim($basePath, '/');

        $meta = (array) ($lesson->analysis_meta ?? []);
        $meta['read_aloud'] = is_array($meta['read_aloud'] ?? null) ? $meta['read_aloud'] : [];
        $meta['read_aloud'][$speed] = is_array($meta['read_aloud'][$speed] ?? null) ? $meta['read_aloud'][$speed] : [];

        $readAloudMeta = [
            'disk' => $disk,
            'speed' => $speed,
            'format' => $format,
            'mode' => $effectiveMode,
            'voice_pair' => $voicePair,
            'voices' => [
                'narrator' => $voices['narrator'],
                'voice_a' => $voices['voice_a'],
                'voice_b' => $voices['voice_b'],
            ],
            'locale' => $locale,
            'base_path' => $basePath,
            'base_url' => $baseUrl,
            'speaker_map' => $speakerMap,
            'parts' => $parts,
            'generated_at' => now()->toISOString(),
        ];

        $meta['read_aloud'][$speed][$format] = $readAloudMeta;

        $lesson->update([
            'audio_path' => $basePath,
            'audio_url' => $baseUrl,
            'analysis_meta' => $meta,
        ]);

        return [
            'exists' => count($parts) > 0,
            'parts' => array_map(fn ($p) => [
                'index' => $p['index'],
                'url' => $p['url'],
                'chars' => $p['chars'],
                'voice' => $p['voice'],
            ], $parts),
            'speed' => $speed,
            'format' => $format,
            'mode' => $effectiveMode,
            'voice_pair' => $voicePair,
            'voice' => $voices['narrator'],
            'voices' => [
                'narrator' => $voices['narrator'],
                'voice_a' => $voices['voice_a'],
                'voice_b' => $voices['voice_b'],
            ],
            'locale' => $locale,
            'base_url' => $baseUrl,
            'generated_at' => $readAloudMeta['generated_at'],
            'speaker_map' => $speakerMap,
        ];
    }

    protected function buildStoryThenDialogueSegments(string $storyText, array $dialogueRows): array
    {
        $segments = [];

        $storyText = trim($storyText);
        if ($storyText !== '') {
            $paras = preg_split("/\n{2,}/u", $storyText) ?: [];
            $paras = array_values(array_filter(array_map(fn ($p) => trim((string) $p), $paras)));

            foreach ($paras as $p) {
                if ($p === '') continue;
                $segments[] = ['speaker' => null, 'text' => $p];
            }
        }

        $dlg = [];
        foreach ($dialogueRows as $row) {
            if (!is_array($row)) continue;
            $sp = trim((string) ($row['speaker'] ?? ''));
            $tx = trim((string) ($row['text'] ?? ''));
            if ($tx === '') continue;
            $dlg[] = [
                'speaker' => $sp !== '' ? $sp : null,
                'text' => $tx,
            ];
        }

        if (count($dlg)) {
            $segments[] = ['speaker' => null, 'text' => ''];
            foreach ($dlg as $d) $segments[] = $d;
        }

        return $segments;
    }

    protected function buildVoiceRuns(array $speakerSegments, array $voices, string $mode, int $maxCharsPerRun = 2200): array
    {
        $mode = $this->normalizeMode($mode);

        $speakerMap = in_array($mode, ['quote', 'mixed'], true)
            ? $this->speakerVoiceMap($speakerSegments, $voices)
            : [];

        $items = [];
        $alt = 0;

        foreach ($speakerSegments as $seg) {
            if (!is_array($seg)) continue;

            $speaker = is_string($seg['speaker'] ?? null) ? trim((string) $seg['speaker']) : '';
            $text = trim((string) ($seg['text'] ?? ''));
            if ($text === '') continue;

            $text = $this->stripPossibleSpeakerPrefix($text);

            $voice = (string) ($voices['narrator'] ?? '');
            if ($voice === '') continue;

            if ($mode === 'quote') {
                if ($speaker !== '' && isset($speakerMap[$speaker])) {
                    $voice = (string) $speakerMap[$speaker];
                } else {
                    $voice = ($alt % 2 === 0)
                        ? (string) ($voices['voice_a'] ?? $voice)
                        : (string) ($voices['voice_b'] ?? $voice);
                    $alt++;
                }
            }

            if ($mode === 'mixed') {
                if ($speaker !== '') {
                    if (isset($speakerMap[$speaker])) {
                        $voice = (string) $speakerMap[$speaker];
                    } else {
                        $voice = ($alt % 2 === 0)
                            ? (string) ($voices['voice_a'] ?? $voice)
                            : (string) ($voices['voice_b'] ?? $voice);
                        $alt++;
                    }
                }
            }

            $items[] = [
                'voice' => $voice,
                'speaker' => $speaker !== '' ? $speaker : null,
                'text' => $text,
            ];
        }

        $runs = [];
        $bufVoice = null;
        $buf = [];
        $len = 0;

        foreach ($items as $it) {
            $v = (string) ($it['voice'] ?? '');
            $t = trim((string) ($it['text'] ?? ''));
            if ($v === '' || $t === '') continue;

            $l = mb_strlen($t);

            if ($bufVoice === null) {
                $bufVoice = $v;
                $buf = [$t];
                $len = $l;
                continue;
            }

            $sameVoice = $v === $bufVoice;
            $fits = ($len + 1 + $l) <= $maxCharsPerRun;

            if ($sameVoice && $fits) {
                $buf[] = $t;
                $len += 1 + $l;
                continue;
            }

            $runs[] = ['voice' => $bufVoice, 'segments' => $buf];

            $bufVoice = $v;
            $buf = [$t];
            $len = $l;
        }

        if ($bufVoice !== null && count($buf)) {
            $runs[] = ['voice' => $bufVoice, 'segments' => $buf];
        }

        return [
            'mode' => $mode,
            'speaker_map' => $speakerMap,
            'runs' => $runs,
        ];
    }

    protected function buildSingleVoiceSsml(array $segments, string $locale, string $voiceName, string $speed): string
    {
        $rate = match ($speed) {
            'slow' => '-15%',
            'fast' => '+12%',
            default => '0%',
        };

        $break = match ($speed) {
            'slow' => '320ms',
            'fast' => '140ms',
            default => '220ms',
        };

        $clean = [];
        foreach ($segments as $s) {
            $t = $this->sanitizeText((string) $s);
            if ($t === '') continue;
            $clean[] = '<s>' . $this->e($t) . '</s>';
        }

        $body = implode('<break time="' . $this->e($break) . '"/>', $clean);

        return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="' . $this->e($locale) . '">'
            . '<voice name="' . $this->e($voiceName) . '">'
            . '<prosody rate="' . $this->e($rate) . '">' . $body . '</prosody>'
            . '</voice>'
            . '</speak>';
    }

    protected function pickVoices(string $locale, string $voicePair): array
    {
        $voicePair = strtolower(trim($voicePair));
        if (!in_array($voicePair, ['auto', 'female_male', 'female_female', 'male_male'], true)) {
            $voicePair = 'auto';
        }

        $female = $this->tts->pickVoiceShortName($locale, 'Female', 'chat')
            ?: $this->tts->pickVoiceShortName($locale, 'Female', null);

        $male = $this->tts->pickVoiceShortName($locale, 'Male', 'chat')
            ?: $this->tts->pickVoiceShortName($locale, 'Male', null);

        $narrator = $female ?: $male;

        $a = $female ?: $narrator;
        $b = $male ?: $narrator;

        if ($voicePair === 'female_female') {
            $a = $female ?: $narrator;
            $b = $female ?: $narrator;
        } elseif ($voicePair === 'male_male') {
            $a = $male ?: $narrator;
            $b = $male ?: $narrator;
        } else {
            $a = $female ?: $narrator;
            $b = $male ?: $narrator;
        }

        return [
            'narrator' => $narrator,
            'voice_a' => $a,
            'voice_b' => $b,
        ];
    }

    protected function looksLikeDialogue(array $segments): bool
    {
        $speakers = [];
        $speakerLines = 0;

        foreach ($segments as $s) {
            if (!is_array($s)) continue;
            $sp = $s['speaker'] ?? null;
            if (is_string($sp) && trim($sp) !== '') {
                $speakers[trim($sp)] = true;
                $speakerLines++;
            }
        }

        return count($speakers) >= 2 && $speakerLines >= 4;
    }

    protected function speakerVoiceMap(array $segments, array $voices): array
    {
        $speakerNames = [];
        foreach ($segments as $s) {
            if (!is_array($s)) continue;
            $sp = $s['speaker'] ?? null;
            if (is_string($sp) && trim($sp) !== '') {
                $speakerNames[trim($sp)] = true;
            }
        }

        $names = array_values(array_keys($speakerNames));
        if (count($names) < 2) return [];

        $a = (string) ($voices['voice_a'] ?? ($voices['narrator'] ?? ''));
        $b = (string) ($voices['voice_b'] ?? ($voices['narrator'] ?? ''));
        if ($a === '' || $b === '') return [];

        $map = [];
        $map[$names[0]] = $a;
        $map[$names[1]] = $b;

        for ($i = 2; $i < count($names); $i++) {
            $map[$names[$i]] = ($i % 2 === 0) ? $a : $b;
        }

        return $map;
    }

    protected function stripPossibleSpeakerPrefix(string $text): string
    {
        $t = trim($text);
        if ($t === '') return '';
        $t2 = preg_replace('/^\s*[^\:\：]{1,28}\s*[\:\：]\s*/u', '', $t);
        return trim((string) ($t2 ?? $t));
    }

    protected function sanitizeText(string $text): string
    {
        $t = trim($text);
        if ($t === '') return '';

        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return trim($t);
    }

    protected function charsCount(array $segments): int
    {
        $c = 0;
        foreach ($segments as $s) {
            $c += mb_strlen((string) $s);
        }
        return $c;
    }

    protected function normalizeMode(string $mode): string
    {
        $m = strtolower(trim($mode));
        if (!in_array($m, ['auto', 'narration', 'quote', 'dialogue', 'mixed'], true)) {
            return 'auto';
        }
        if ($m === 'dialogue') return 'quote';
        return $m;
    }

    protected function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected function toAzureLocale(?string $languageCode): string
    {
        $code = strtolower(trim((string) $languageCode));
        if ($code === '') return 'en-US';
        if (str_starts_with($code, 'en')) return 'en-US';
        if (str_starts_with($code, 'nl')) return 'nl-NL';
        if (str_starts_with($code, 'fa')) return 'fa-IR';
        return 'en-US';
    }

    public function getExisting(
        Lesson $lesson,
        string $speed = 'normal',
        string $format = 'mp3'
    ): array {
        $speed = in_array($speed, ['slow', 'normal', 'fast'], true) ? $speed : 'normal';
        $format = in_array($format, ['mp3', 'wav'], true) ? $format : 'mp3';

        $meta = (array) ($lesson->analysis_meta ?? []);
        $read = $meta['read_aloud'] ?? null;

        if (!is_array($read)) {
            return [
                'exists' => false,
                'parts' => [],
                'speed' => $speed,
                'format' => $format,
                'locale' => $this->toAzureLocale((string) ($lesson->target_language ?? 'en')),
                'base_url' => null,
                'generated_at' => null,
            ];
        }

        $bucket = $read[$speed][$format] ?? null;
        if (!is_array($bucket)) {
            return [
                'exists' => false,
                'parts' => [],
                'speed' => $speed,
                'format' => $format,
                'locale' => $this->toAzureLocale((string) ($lesson->target_language ?? 'en')),
                'base_url' => null,
                'generated_at' => null,
            ];
        }

        $parts = $bucket['parts'] ?? [];
        if (!is_array($parts)) $parts = [];

        $outParts = [];
        foreach ($parts as $p) {
            if (!is_array($p)) continue;
            $idx = (int) ($p['index'] ?? 0);
            $url = (string) ($p['url'] ?? '');
            $chars = (int) ($p['chars'] ?? 0);
            if ($idx <= 0 || $url === '') continue;
            $outParts[] = ['index' => $idx, 'url' => $url, 'chars' => $chars];
        }

        return [
            'exists' => count($outParts) > 0,
            'parts' => $outParts,
            'speed' => $speed,
            'format' => $format,
            'locale' => (string) ($bucket['locale'] ?? $this->toAzureLocale((string) ($lesson->target_language ?? 'en'))),
            'base_url' => (string) ($bucket['base_url'] ?? null),
            'generated_at' => (string) ($bucket['generated_at'] ?? null),
        ];
    }
}
