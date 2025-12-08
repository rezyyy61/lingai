<?php

namespace App\Enums;

enum LessonNlpTask: string
{
    case FullLesson = 'full_lesson';
    case WordsOnly = 'words_only';
    case SentencesOnly = 'sentences_only';
    case ExercisesOnly = 'exercises_only';
}
