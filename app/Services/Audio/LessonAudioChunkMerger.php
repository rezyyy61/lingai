<?php

namespace App\Services\Audio;

use RuntimeException;

class LessonAudioChunkMerger
{
    public function merge(array $chunks, string $format = 'mp3'): string
    {
        if ($chunks === []) {
            throw new RuntimeException('No lesson audio chunks were generated.');
        }

        $format = strtolower(trim($format)) === 'wav' ? 'wav' : 'mp3';
        $workspace = $this->makeWorkspace();

        try {
            $inputPaths = [];

            foreach (array_values($chunks) as $index => $chunk) {
                $binary = $chunk['binary'] ?? null;

                if (! is_string($binary) || $binary === '') {
                    continue;
                }

                $segmentPath = $this->normalizeChunkToWav(
                    binary: $binary,
                    workspace: $workspace,
                    index: $index,
                    inputFormat: (string) ($chunk['input_format'] ?? 'wav'),
                );
                $inputPaths[] = $segmentPath;

                $pauseMs = max(0, (int) ($chunk['pause_ms'] ?? 0));

                if ($pauseMs > 0) {
                    $silencePath = $workspace . '/pause-' . $index . '.wav';
                    $this->runCommand([
                        'ffmpeg',
                        '-y',
                        '-f',
                        'lavfi',
                        '-i',
                        'anullsrc=r=24000:cl=mono',
                        '-t',
                        number_format($pauseMs / 1000, 3, '.', ''),
                        '-ac',
                        '1',
                        '-ar',
                        '24000',
                        '-c:a',
                        'pcm_s16le',
                        $silencePath,
                    ], 'ffmpeg silence generation failed');

                    $inputPaths[] = $silencePath;
                }
            }

            if ($inputPaths === []) {
                throw new RuntimeException('No lesson audio chunks were generated.');
            }

            $concatListPath = $workspace . '/concat.txt';
            $concatContents = implode("\n", array_map(
                static fn (string $path) => "file '" . str_replace("'", "'\\''", $path) . "'",
                $inputPaths,
            ));
            file_put_contents($concatListPath, $concatContents . "\n");

            $mergedWavPath = $workspace . '/merged.wav';
            $this->runCommand([
                'ffmpeg',
                '-y',
                '-f',
                'concat',
                '-safe',
                '0',
                '-i',
                $concatListPath,
                '-c',
                'copy',
                $mergedWavPath,
            ], 'ffmpeg audio concat failed');

            $finalPath = $mergedWavPath;

            if ($format === 'mp3') {
                $finalPath = $workspace . '/final.mp3';
                $this->runCommand([
                    'ffmpeg',
                    '-y',
                    '-i',
                    $mergedWavPath,
                    '-codec:a',
                    'libmp3lame',
                    '-b:a',
                    '160k',
                    $finalPath,
                ], 'ffmpeg mp3 conversion failed');
            }

            $binary = @file_get_contents($finalPath);

            if (! is_string($binary) || $binary === '') {
                throw new RuntimeException('Merged lesson audio file is empty.');
            }

            return $binary;
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    protected function makeWorkspace(): string
    {
        $baseDirectory = rtrim(sys_get_temp_dir(), '/') . '/' . trim((string) config('lesson_generation.audio.temp_directory', 'lesson-audio'), '/');

        if (! is_dir($baseDirectory)) {
            mkdir($baseDirectory, 0777, true);
        }

        $workspace = $baseDirectory . '/' . bin2hex(random_bytes(10));

        if (! mkdir($workspace, 0777, true) && ! is_dir($workspace)) {
            throw new RuntimeException('Could not create lesson audio temp workspace.');
        }

        return $workspace;
    }

    protected function cleanupWorkspace(string $workspace): void
    {
        if (! is_dir($workspace)) {
            return;
        }

        $files = glob($workspace . '/*');

        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        @rmdir($workspace);
    }

    protected function normalizeChunkToWav(string $binary, string $workspace, int $index, string $inputFormat): string
    {
        $normalizedInputFormat = strtolower(trim($inputFormat));
        $inputExtension = $this->inputExtension($normalizedInputFormat);
        $inputPath = $workspace . '/segment-' . $index . '-input.' . $inputExtension;
        $outputPath = $workspace . '/segment-' . $index . '.wav';

        file_put_contents($inputPath, $binary);

        if ($normalizedInputFormat === 'wav' || str_contains($normalizedInputFormat, 'riff')) {
            $this->runCommand([
                'ffmpeg',
                '-y',
                '-i',
                $inputPath,
                '-ac',
                '1',
                '-ar',
                '24000',
                '-c:a',
                'pcm_s16le',
                $outputPath,
            ], 'ffmpeg wav normalization failed');

            return $outputPath;
        }

        $this->runCommand([
            'ffmpeg',
            '-y',
            '-i',
            $inputPath,
            '-ac',
            '1',
            '-ar',
            '24000',
            '-c:a',
            'pcm_s16le',
            $outputPath,
        ], 'ffmpeg input normalization failed');

        return $outputPath;
    }

    protected function inputExtension(string $format): string
    {
        return match (true) {
            str_contains($format, 'riff'), str_contains($format, 'wav') => 'wav',
            str_contains($format, 'ogg') => 'ogg',
            str_contains($format, 'mpeg'), str_contains($format, 'mp3') => 'mp3',
            default => 'bin',
        };
    }

    protected function runCommand(array $parts, string $errorPrefix): void
    {
        $command = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
        $output = [];
        $code = 0;

        exec($command, $output, $code);

        if ($code !== 0) {
            throw new RuntimeException($errorPrefix . ': ' . implode("\n", array_slice($output, 0, 20)));
        }
    }
}
