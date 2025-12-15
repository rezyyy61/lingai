<?php

namespace App\Services\Ai;

class LlmResult
{
    public function __construct(
        public bool $ok,
        public int $status,
        public ?string $content,
        public ?array $json,
        public ?string $finishReason,
        public ?array $usage,
        public ?array $error,
        public ?array $raw,
    ) {}
}
