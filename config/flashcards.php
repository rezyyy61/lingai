<?php

return [
    'review' => [
        'session_limit' => env('FLASHCARD_REVIEW_SESSION_LIMIT', 20),
        'initial_ease_factor' => env('FLASHCARD_REVIEW_INITIAL_EASE_FACTOR', 2.3),
        'min_ease_factor' => env('FLASHCARD_REVIEW_MIN_EASE_FACTOR', 1.3),
        'max_ease_factor' => env('FLASHCARD_REVIEW_MAX_EASE_FACTOR', 3.0),
        'success_ease_step' => env('FLASHCARD_REVIEW_SUCCESS_EASE_STEP', 0.15),
        'failure_ease_step' => env('FLASHCARD_REVIEW_FAILURE_EASE_STEP', 0.2),
        'again_interval_seconds' => env('FLASHCARD_REVIEW_AGAIN_INTERVAL_SECONDS', 300),
        'failure_step_seconds' => env('FLASHCARD_REVIEW_FAILURE_STEP_SECONDS', 300),
        'max_failure_interval_seconds' => env('FLASHCARD_REVIEW_MAX_FAILURE_INTERVAL_SECONDS', 3600),
        'first_success_interval_seconds' => env('FLASHCARD_REVIEW_FIRST_SUCCESS_INTERVAL_SECONDS', 43200),
        'second_success_interval_seconds' => env('FLASHCARD_REVIEW_SECOND_SUCCESS_INTERVAL_SECONDS', 86400),
        'success_growth_multiplier' => env('FLASHCARD_REVIEW_SUCCESS_GROWTH_MULTIPLIER', 1.6),
        'mastered_interval_seconds' => env('FLASHCARD_REVIEW_MASTERED_INTERVAL_SECONDS', 1209600),
    ],
];
