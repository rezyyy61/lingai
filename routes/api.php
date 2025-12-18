<?php

use App\Http\Controllers\Api\LanguageController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\LessonExerciseController;
use App\Http\Controllers\Api\LessonFromAudioController;
use App\Http\Controllers\Api\LessonFromYoutubeController;
use App\Http\Controllers\Api\LessonGrammarController;
use App\Http\Controllers\Api\LessonReadAloudController;
use App\Http\Controllers\Api\LessonSentenceController;
use App\Http\Controllers\Api\LessonSentenceTtsController;
use App\Http\Controllers\Api\LessonWordController;
use App\Http\Controllers\Api\LessonWordTtsController;
use App\Http\Controllers\Api\SpeakingPracticeController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->group(function () {

    Route::get('languages', [LanguageController::class, 'index']);
    Route::get('workspaces', [WorkspaceController::class, 'index']);
    Route::post('workspaces', [WorkspaceController::class, 'store']);
    Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show']);

    Route::post('workspaces/{workspace}/lessons', [LessonController::class, 'store']);

    Route::apiResource('lessons', LessonController::class)->only([
        'index',
        'show',
        'store',
        'destroy',
    ]);
    Route::post('lessons/{lesson}/analysis/generate', [LessonController::class, 'generateAnalysis']);
    Route::post('/workspaces/{workspace}/lessons/generate', [LessonController::class, 'generate']);

    Route::post('workspaces/{workspace}/lessons/from-audio', [LessonFromAudioController::class, 'store']);
    Route::post('workspaces/{workspace}/lessons/from-youtube', [LessonFromYoutubeController::class, 'store']);


    Route::get('lessons/{lesson}/words', [LessonWordController::class, 'index']);
    Route::post('lessons/{lesson}/words', [LessonWordController::class, 'store']);
    Route::post('lessons/{lesson}/words/generate', [LessonWordController::class, 'generate']);
    Route::get('lessons/{lesson}/words/{word}', [LessonWordController::class, 'show']);
    Route::put('lessons/{lesson}/words/{word}', [LessonWordController::class, 'update']);
    Route::delete('lessons/{lesson}/words/{word}', [LessonWordController::class, 'destroy']);

    Route::get('lessons/{lesson}/sentences', [LessonSentenceController::class, 'index']);
    Route::get('lessons/{lesson}/sentences/{sentence}', [LessonSentenceController::class, 'show']);
    Route::post('lessons/{lesson}/sentences/generate', [LessonSentenceController::class, 'generate']);

    Route::get('lesson-words/{word}/tts', [LessonWordTtsController::class, 'show']);
    Route::get('lesson-sentences/{sentence}/tts', [LessonSentenceTtsController::class, 'show']);


    Route::get('lessons/{lesson}/exercises', [LessonExerciseController::class, 'index']);
    Route::get('lessons/{lesson}/exercises/{exercise}', [LessonExerciseController::class, 'show']);
    Route::post('lesson-exercises/{exercise}/attempt', [LessonExerciseController::class, 'attempt']);
    Route::post('lessons/{lesson}/exercises/generate', [LessonExerciseController::class, 'generate']);

    Route::get('lessons/{lesson}/grammar', [LessonGrammarController::class, 'index']);
    Route::get('lessons/{lesson}/grammar/{grammarPoint}', [LessonGrammarController::class, 'show']);
    Route::post('lessons/{lesson}/grammar/generate', [LessonGrammarController::class, 'generate']);

    Route::post('/speaking/submit', [SpeakingPracticeController::class, 'submit']);


    Route::get('/lessons/{lesson}/read-aloud', [LessonReadAloudController::class, 'show']);
    Route::post('/lessons/{lesson}/read-aloud', [LessonReadAloudController::class, 'generate']);
});

require __DIR__.'/auth.php';
