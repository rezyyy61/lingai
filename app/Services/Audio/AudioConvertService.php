<?php

namespace App\Services\Audio;

class AudioConvertService
{
    public function toWav16kMono(string $inputPath): string
    {
        $out = sys_get_temp_dir() . '/' . bin2hex(random_bytes(10)) . '.wav';

        $cmd = 'ffmpeg -y -i ' . escapeshellarg($inputPath)
            . ' -ac 1 -ar 16000 -f wav ' . escapeshellarg($out) . ' 2>&1';

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        if ($code !== 0 || ! is_file($out) || filesize($out) < 200) {
            throw new \RuntimeException('ffmpeg convert failed: ' . implode("\n", array_slice($output, 0, 20)));
        }

        return $out;
    }
}
