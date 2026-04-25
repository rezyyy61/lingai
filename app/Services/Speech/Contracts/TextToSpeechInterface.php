<?php

namespace App\Services\Speech\Contracts;

interface TextToSpeechInterface
{
    public function providerName(): string;

    public function supportsFeature(string $feature): bool;

    public function synthesizeShadowing(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
        string $speed = 'slow',
        ?string $preset = null,
        string $feature = 'practice_shadowing',
    ): string;

    public function synthesizeShadowingDetailed(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
        string $speed = 'slow',
        ?string $preset = null,
        string $feature = 'practice_shadowing',
    ): array;

    public function synthesizeLessonSegment(
        string $text,
        ?string $languageCode = null,
        ?string $speaker = null,
        ?string $style = null,
        string $format = 'wav',
        array $options = [],
    ): array;

    public function synthesizeReadAloudText(
        string $text,
        ?string $languageCode = null,
        ?string $voice = null,
        string $format = 'wav',
        array $options = [],
    ): array;
}
