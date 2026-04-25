<?php

namespace App\Services\Speech;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TtsAudioStorage
{
    public function store(string $binary, string $disk, string $directory, string $outputFormat): array
    {
        $path = $this->buildPath($directory, $outputFormat);

        Storage::disk($disk)->put($path, $binary);

        return [
            'disk' => $disk,
            'path' => $path,
            'url' => Storage::disk($disk)->url($path),
        ];
    }

    public function buildPath(string $directory, string $outputFormat): string
    {
        $directory = trim($directory, '/');

        return $directory . '/' . Str::uuid() . '.' . $this->extensionForOutputFormat($outputFormat);
    }

    public function extensionForOutputFormat(string $outputFormat): string
    {
        $format = strtolower(trim($outputFormat));

        return match (true) {
            str_contains($format, 'wav'), str_contains($format, 'riff') => 'wav',
            str_contains($format, 'ogg') => 'ogg',
            str_contains($format, 'ulaw') => 'ulaw',
            default => 'mp3',
        };
    }
}
