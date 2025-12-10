<?php

namespace App\Services\Youtube;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Facades\Log;
use MrMySQL\YoutubeTranscript\Exceptions\NoTranscriptFoundException;
use MrMySQL\YoutubeTranscript\Exceptions\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;

class YoutubeTranscriptService
{
    protected TranscriptListFetcher $fetcher;

    public function __construct()
    {
        $httpClient = new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; LingaiBot/1.0; +https://lingai.nl)',
            ],
        ]);

        $httpFactory = new HttpFactory();

        $this->fetcher = new TranscriptListFetcher($httpClient, $httpFactory, $httpFactory);
    }

    public function getTranscriptTextFromUrl(string $url, string $language = 'en'): ?string
    {
        $videoId = $this->extractVideoId($url);

        if (! $videoId) {
            Log::warning('YoutubeTranscriptService: could not extract video ID from URL', [
                'url' => $url,
            ]);

            return null;
        }

        try {
            $list = $this->fetcher->fetch($videoId);

            $transcript = $list->findTranscript([$language]);

            $segments = $transcript->fetch();

            $parts = [];

            foreach ($segments as $segment) {
                if (! empty($segment['text'])) {
                    $parts[] = $segment['text'];
                }
            }

            $text = trim(implode(' ', $parts));

            if ($text === '') {
                Log::warning('YoutubeTranscriptService: empty transcript text', [
                    'video_id' => $videoId,
                    'language' => $language,
                ]);

                return null;
            }

            return $text;
        } catch (NoTranscriptFoundException|TranscriptsDisabledException $e) {
            Log::warning('YoutubeTranscriptService: no transcript or disabled', [
                'video_id' => $videoId,
                'language' => $language,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('YoutubeTranscriptService: unexpected error while fetching transcript', [
                'video_id' => $videoId,
                'language' => $language,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            report($e);

            return null;
        }
    }

    protected function extractVideoId(string $url): ?string
    {
        if (preg_match('~youtu\.be/([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~youtube\.com/watch\?v=([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~youtube\.com/embed/([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        return null;
    }
}
