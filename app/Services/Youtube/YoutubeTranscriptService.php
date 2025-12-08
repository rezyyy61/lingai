<?php

namespace App\Services\Youtube;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use MrMySQL\YoutubeTranscript\Exceptions\NoTranscriptFoundException;
use MrMySQL\YoutubeTranscript\Exceptions\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;

class YoutubeTranscriptService
{
    protected TranscriptListFetcher $fetcher;

    public function __construct()
    {
        $httpClient = new Client();
        $httpFactory = new HttpFactory();

        $this->fetcher = new TranscriptListFetcher($httpClient, $httpFactory, $httpFactory);
    }

    public function getTranscriptTextFromUrl(string $url, string $language = 'en'): ?string
    {
        $videoId = $this->extractVideoId($url);

        if (! $videoId) {
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

            return trim(implode(' ', $parts));
        } catch (NoTranscriptFoundException|TranscriptsDisabledException $e) {
            return null;
        } catch (\Throwable $e) {
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
