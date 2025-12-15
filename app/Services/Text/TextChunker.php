<?php

namespace App\Services\Text;

class TextChunker
{
    public function plan(string $text, ChunkPolicy $policy): ChunkPlan
    {
        $text = $this->normalize($text);
        $totalWords = $this->wordCount($text);
        $totalChars = mb_strlen($text);

        if ($text === '') {
            return new ChunkPlan([], $policy->targetWords, $policy->overlapWords, 0, 0);
        }

        $sentences = $this->splitSentences($text);
        if (count($sentences) === 1) {
            $chunks = $this->chunkByWordsWithOverlap($text, $policy->targetWords, $policy->overlapWords, $policy->maxChunks);
            return new ChunkPlan($chunks, $policy->targetWords, $policy->overlapWords, $totalWords, $totalChars);
        }

        $chunks = [];
        $buf = '';
        $bufWords = 0;

        foreach ($sentences as $s) {
            $s = trim($s);
            if ($s === '') continue;

            $w = $this->wordCount($s);

            if ($bufWords > 0 && ($bufWords + $w) > $policy->targetWords) {
                $chunks[] = trim($buf);

                if (count($chunks) >= $policy->maxChunks) {
                    break;
                }

                $tail = $this->tailWords($buf, $policy->overlapWords);
                $buf = $tail !== '' ? $tail . ' ' . $s : $s;
                $bufWords = $this->wordCount($buf);
                continue;
            }

            $buf = $buf === '' ? $s : ($buf . ' ' . $s);
            $bufWords += $w;
        }

        if (count($chunks) < $policy->maxChunks && trim($buf) !== '') {
            $chunks[] = trim($buf);
        }

        $chunks = $this->capChunksAndEnsureNonEmpty($chunks, $policy->maxChunks);

        return new ChunkPlan($chunks, $policy->targetWords, $policy->overlapWords, $totalWords, $totalChars);
    }

    protected function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    protected function splitSentences(string $text): array
    {
        $parts = preg_split('/(?<=[\.\!\?\n])\s+/u', $text);
        if (!is_array($parts) || empty($parts)) {
            return [$text];
        }

        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') $out[] = $p;
        }

        return $out ?: [$text];
    }

    protected function wordCount(string $text): int
    {
        $t = $this->normalize($text);
        if ($t === '') return 0;

        $parts = preg_split('/\s+/u', $t);
        return is_array($parts) ? count($parts) : 0;
    }

    protected function tailWords(string $text, int $n): string
    {
        if ($n <= 0) return '';

        $t = $this->normalize($text);
        if ($t === '') return '';

        $words = preg_split('/\s+/u', $t);
        if (!is_array($words) || empty($words)) return '';

        $slice = array_slice($words, max(0, count($words) - $n));
        return trim(implode(' ', $slice));
    }

    protected function chunkByWordsWithOverlap(string $text, int $targetWords, int $overlapWords, int $maxChunks): array
    {
        $t = $this->normalize($text);
        $words = preg_split('/\s+/u', $t);
        if (!is_array($words) || empty($words)) return [];

        $chunks = [];
        $i = 0;
        $step = max(1, $targetWords - max(0, $overlapWords));

        while ($i < count($words) && count($chunks) < $maxChunks) {
            $slice = array_slice($words, $i, $targetWords);
            $chunks[] = trim(implode(' ', $slice));
            $i += $step;
        }

        return $this->capChunksAndEnsureNonEmpty($chunks, $maxChunks);
    }

    protected function capChunksAndEnsureNonEmpty(array $chunks, int $maxChunks): array
    {
        $out = [];
        foreach ($chunks as $c) {
            $c = trim((string) $c);
            if ($c !== '') $out[] = $c;
            if (count($out) >= $maxChunks) break;
        }
        return $out;
    }
}
