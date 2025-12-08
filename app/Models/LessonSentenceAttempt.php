<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LessonSentenceAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'raw_response' => 'array',
    ];

    public function sentence()
    {
        return $this->belongsTo(LessonSentence::class, 'lesson_sentence_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
