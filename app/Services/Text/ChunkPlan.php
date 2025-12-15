<?php

namespace App\Services\Text;

class ChunkPlan
{
    public function __construct(
        public readonly array $chunks,
        public readonly int $targetWords,
        public readonly int $overlapWords,
        public readonly int $totalWords,
        public readonly int $totalChars
    ) {}
}
