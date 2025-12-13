<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base' => env('OPENAI_API_BASE', 'https://api.openai.com/v1'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4.1-mini'),
        'tts_model' => env('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts'),
        'stt_model' => env('OPENAI_STT_MODEL', 'whisper-1'),
    ],

    'youtube_transcript' => [
        'yt_dlp_bin' => env('YT_DLP_BIN', '/opt/yt/bin/yt-dlp'),
        'cookies_file' => env('YT_COOKIES_FILE', '/cookies/youtube-cookies.txt'),
        'js_runtimes' => env('YT_JS_RUNTIMES', 'node:/usr/bin/node'),
        'remote_components' => env('YT_REMOTE_COMPONENTS', ''),
        'yt_dlp_timeout' => env('YT_DLP_TIMEOUT', 45),
        'yt_dlp_max_chars' => env('YT_DLP_MAX_CHARS', 60000),

        'user_agent' => env('YT_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'),
        'accept_language' => env('YT_ACCEPT_LANGUAGE', 'en-US,en;q=0.9'),
        'accept' => env('YT_ACCEPT', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
    ]
];
