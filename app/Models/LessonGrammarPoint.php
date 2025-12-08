<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LessonGrammarPoint extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'examples' => 'array',
        'meta' => 'array',
    ];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}
