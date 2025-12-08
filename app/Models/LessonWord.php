<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class LessonWord extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    protected $appends = [
        'tts_audio_url',
    ];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function sentences()
    {
        return $this->belongsToMany(LessonSentence::class, 'lesson_sentence_word');
    }

    public function getTtsAudioUrlAttribute(): ?string
    {
        if (! $this->tts_audio_path) {
            return null;
        }

        if (Storage::disk('public')->exists($this->tts_audio_path)) {
            return Storage::disk('public')->url($this->tts_audio_path);
        }

        return null;
    }
}
