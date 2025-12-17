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
        'provider' => env('OPENAI_PROVIDER', 'openai'), // openai | azure
        // Shared tuning for this service
        'words_timeout' => env('OPENAI_WORDS_TIMEOUT', 25),
        'words_connect_timeout' => env('OPENAI_WORDS_CONNECT_TIMEOUT', 5),
        'words_max_tokens' => env('OPENAI_WORDS_MAX_TOKENS', 650),
        'words_max_chars' => env('OPENAI_WORDS_MAX_CHARS', 6000),

        // Azure OpenAI
        'azure_endpoint' => env('AZURE_OPENAI_ENDPOINT'),
        'azure_key' => env('AZURE_OPENAI_API_KEY'),
        'azure_api_version' => env('AZURE_OPENAI_API_VERSION', '2025-01-01-preview'),
        'azure_deployment_words' => env('AZURE_OPENAI_DEPLOYMENT_WORDS', env('AZURE_OPENAI_DEPLOYMENT')),
        'azure_words_use_max_completion_tokens' => env('AZURE_WORDS_USE_MAX_COMPLETION_TOKENS', true),
        'azure_use_v1' => env('AZURE_OPENAI_USE_V1', true),

        'words_chunk_overlap_words' => env('OPENAI_WORDS_CHUNK_OVERLAP_WORDS', 12),
        'words_chunk_max_chunks' => env('OPENAI_WORDS_CHUNK_MAX_CHUNKS', 6),
        'words_time_budget_ms' => env('OPENAI_WORDS_TIME_BUDGET_MS', 55000),

        'sentences_chunk_target_words' => env('OPENAI_SENTENCES_CHUNK_TARGET_WORDS', 520),
        'sentences_chunk_overlap_words' => env('OPENAI_SENTENCES_CHUNK_OVERLAP_WORDS', 10),
        'sentences_chunk_max_chunks' => env('OPENAI_SENTENCES_CHUNK_MAX_CHUNKS', 5),
        'sentences_time_budget_ms' => env('OPENAI_SENTENCES_TIME_BUDGET_MS', 45000),

        'grammar_chunk_target_words' => env('OPENAI_GRAMMAR_CHUNK_TARGET_WORDS', 650),
        'grammar_chunk_overlap_words' => env('OPENAI_GRAMMAR_CHUNK_OVERLAP_WORDS', 12),
        'grammar_chunk_max_chunks' => env('OPENAI_GRAMMAR_CHUNK_MAX_CHUNKS', 4),
        'grammar_time_budget_ms' => env('OPENAI_GRAMMAR_TIME_BUDGET_MS', 50000),

        'exercises_chunk_target_words' => env('OPENAI_EXERCISES_CHUNK_TARGET_WORDS', 520),
        'exercises_chunk_overlap_words' => env('OPENAI_EXERCISES_CHUNK_OVERLAP_WORDS', 10),
        'exercises_chunk_max_chunks' => env('OPENAI_EXERCISES_CHUNK_MAX_CHUNKS', 5),
        'exercises_time_budget_ms' => env('OPENAI_EXERCISES_TIME_BUDGET_MS', 60000),

        'analysis_chunk_target_words' => env('OPENAI_ANALYSIS_CHUNK_TARGET_WORDS', 650),
        'analysis_chunk_overlap_words' => env('OPENAI_ANALYSIS_CHUNK_OVERLAP_WORDS', 18),
        'analysis_chunk_max_chunks' => env('OPENAI_ANALYSIS_CHUNK_MAX_CHUNKS', 6),
        'analysis_time_budget_ms' => env('OPENAI_ANALYSIS_TIME_BUDGET_MS', 65000),

        'exercises_batch_size' => env('OPENAI_EXERCISES_BATCH_SIZE', 5),
        'exercises_max_completion_tokens' => env('OPENAI_EXERCISES_MAX_COMPLETION_TOKENS', 1400),
        'exercises_prompt_text_max_chars' => env('OPENAI_EXERCISES_PROMPT_TEXT_MAX_CHARS', 3200),


    ],

    'azure_speech' => [
        'key' => env('AZURE_SPEECH_KEY'),
        'region' => env('AZURE_SPEECH_REGION', 'westeurope'),
        'endpoint' => env('AZURE_SPEECH_ENDPOINT'),
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
