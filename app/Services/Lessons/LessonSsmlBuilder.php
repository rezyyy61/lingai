<?php

namespace App\Services\Lessons;

class LessonSsmlBuilder
{
    public function splitToSegments(string $text, int $maxChars = 900): array
    {
        $t = $this->normalizeText($text);
        if ($t === '') return [];

        $parts = preg_split('/(?<=[\.\!\?\。\؟\!])\s+/u', $t) ?: [];
        $out = [];
        $buf = '';

        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p === '') continue;

            if ($buf === '') {
                $buf = $p;
                continue;
            }

            if (mb_strlen($buf) + 1 + mb_strlen($p) <= $maxChars) {
                $buf .= ' ' . $p;
                continue;
            }

            $out[] = $buf;
            $buf = $p;
        }

        if (trim($buf) !== '') {
            $out[] = $buf;
        }

        return array_values($out);
    }

    public function chunkSegments(array $segments, int $maxChars = 2600): array
    {
        $chunks = [];
        $buf = [];
        $len = 0;

        foreach ($segments as $s) {
            $s = (string) $s;
            $l = mb_strlen($s);

            if ($len + $l > $maxChars && count($buf) > 0) {
                $chunks[] = $buf;
                $buf = [];
                $len = 0;
            }

            $buf[] = $s;
            $len += $l;
        }

        if (count($buf) > 0) {
            $chunks[] = $buf;
        }

        return array_values($chunks);
    }

    public function extractSpeakerSegments(string $text): array
    {
        $t = $this->normalizeTextKeepLines($text);
        if ($t === '') return [];

        $t = preg_replace("/\r\n/u", "\n", $t) ?? $t;
        $t = preg_replace("/\n{2,}/u", "\n", $t) ?? $t;

        $t = preg_replace('/\s+([A-Z][a-z]{1,20})\s*:\s*/u', "\n$1: ", $t) ?? $t;

        $lines = explode("\n", $t);
        $out = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (preg_match('/^([^\:]{1,24})\:\s*(.+)$/u', $line, $m)) {
                $speaker = trim((string) ($m[1] ?? ''));
                $content = trim((string) ($m[2] ?? ''));
                if ($content !== '') {
                    $out[] = [
                        'speaker' => $this->normalizeSpeaker($speaker),
                        'text' => $content,
                    ];
                }
                continue;
            }

            $out[] = [
                'speaker' => null,
                'text' => $line,
            ];
        }

        return $out;
    }

    public function buildChunk(
        array $speakerSegments,
        string $locale,
        array $voices,
        string $speed,
        string $mode = 'auto'
    ): array {
        $mode = $this->normalizeMode($mode);

        $hasDialogue = $this->looksLikeDialogue($speakerSegments);
        $effectiveMode = $mode === 'auto'
            ? ($hasDialogue ? 'quote' : 'narration')
            : $mode;

        $rate = match ($speed) {
            'slow' => '-15%',
            'fast' => '+12%',
            default => '0%',
        };

        $map = $this->speakerVoiceMap($speakerSegments, $voices, $effectiveMode);

        $ssml = '<speak version="1.0"'
            . ' xmlns="http://www.w3.org/2001/10/synthesis"'
            . ' xmlns:mstts="http://www.w3.org/2001/mstts"'
            . ' xml:lang="' . $this->e($locale) . '">'
            . "\n";

        $altToggle = 0;

        foreach ($speakerSegments as $seg) {
            if (!is_array($seg)) continue;

            $speaker = $seg['speaker'] ?? null;
            $text = $this->sanitizeSsmlText((string) ($seg['text'] ?? ''));
            if ($text === '') continue;

            $voiceName = $voices['narrator'] ?? null;

            if ($effectiveMode === 'quote' && $hasDialogue) {
                if (is_string($speaker) && $speaker !== '' && isset($map[$speaker])) {
                    $voiceName = $map[$speaker];
                } else {
                    $voiceName = ($altToggle % 2 === 0)
                        ? ($voices['voice_a'] ?? $voiceName)
                        : ($voices['voice_b'] ?? $voiceName);
                    $altToggle++;
                }
            }

            if (!is_string($voiceName) || $voiceName === '') {
                continue;
            }

            $ssml .= '<voice name="' . $this->e($voiceName) . '">';
            $ssml .= '<prosody rate="' . $this->e($rate) . '">';
            $ssml .= $this->e($text);
            $ssml .= '</prosody>';
            $ssml .= '</voice>';
            $ssml .= '<break time="220ms"/>' . "\n";
        }

        $ssml .= '</speak>';

        return [
            'ssml' => $ssml,
            'mode' => $effectiveMode,
            'speaker_map' => $map,
        ];
    }

    protected function sanitizeSsmlText(string $text): string
    {
        $t = trim($text);
        if ($t === '') return '';

        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    protected function looksLikeDialogue(array $segments): bool
    {
        $speakers = [];
        $speakerLines = 0;

        foreach ($segments as $s) {
            if (!is_array($s)) continue;
            $sp = $s['speaker'] ?? null;
            if (is_string($sp) && $sp !== '') {
                $speakers[$sp] = true;
                $speakerLines++;
            }
        }

        return count($speakers) >= 2 && $speakerLines >= 4;
    }

    public function buildSingleVoice(
        array $segments,
        string $locale,
        string $voiceName,
        string $speed = 'normal'
    ): string {
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

        $locale = trim($locale) !== '' ? trim($locale) : 'en-US';
        $voiceName = trim($voiceName);

        $lines = [];
        foreach ($segments as $seg) {
            $t = $this->sanitizeSsmlText((string) $seg);
            if ($t === '') continue;
            $lines[] = '<s>' . $this->e($t) . '</s>';
        }

        $body = implode('<break time="'.$this->e($break).'"/>', $lines);

        return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="'.$this->e($locale).'">'
            . '<voice name="'.$this->e($voiceName).'">'
            . '<prosody rate="'.$this->e($rate).'">'.$body.'</prosody>'
            . '</voice>'
            . '</speak>';
    }

    protected function speakerVoiceMap(array $segments, array $voices, string $mode): array
    {
        if ($mode !== 'quote') return [];

        $speakerNames = [];
        foreach ($segments as $s) {
            if (!is_array($s)) continue;
            $sp = $s['speaker'] ?? null;
            if (is_string($sp) && $sp !== '') {
                $speakerNames[$sp] = true;
            }
        }

        $names = array_keys($speakerNames);
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

    protected function normalizeMode(string $mode): string
    {
        $m = strtolower(trim($mode));
        if (!in_array($m, ['auto', 'narration', 'quote', 'dialogue'], true)) {
            return 'auto';
        }
        if ($m === 'dialogue') return 'quote';
        return $m;
    }

    protected function normalizeSpeaker(string $speaker): string
    {
        $s = trim($speaker);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = mb_substr($s, 0, 24);
        return $s;
    }

    protected function normalizeText(string $text): string
    {
        $raw = strip_tags($text);
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = trim((string) preg_replace('/\s+/u', ' ', $raw));
        return $raw;
    }

    protected function normalizeTextKeepLines(string $text): string
    {
        $raw = strip_tags($text);
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = preg_replace("/[ \t]+/u", ' ', $raw) ?? $raw;
        $raw = preg_replace("/ *\n */u", "\n", $raw) ?? $raw;
        return trim($raw);
    }

    protected function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
