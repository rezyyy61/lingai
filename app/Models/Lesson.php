<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'tags' => 'array',
        'word_prompt_min_items' => 'integer',
        'word_prompt_max_items' => 'integer',
        'analysis_meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function words()
    {
        return $this->hasMany(LessonWord::class);
    }

    public function sentences()
    {
        return $this->hasMany(LessonSentence::class)->orderBy('order_index');
    }

    public function exercises()
    {
        return $this->hasMany(LessonExercise::class);
    }

    public function getTargetLanguageAttribute($value): string
    {
        return $value ?: config('learning_languages.default_target', 'en');
    }

    public function getSupportLanguageAttribute($value): string
    {
        return $value ?: config('learning_languages.default_support', 'en');
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function grammarPoints()
    {
        return $this->hasMany(LessonGrammarPoint::class);
    }

}
