<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LessonExercise extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function sentence()
    {
        return $this->belongsTo(LessonSentence::class, 'lesson_sentence_id');
    }

    public function options()
    {
        return $this->hasMany(LessonExerciseOption::class);
    }

    public function attempts()
    {
        return $this->hasMany(LessonExerciseAttempt::class);
    }
}
