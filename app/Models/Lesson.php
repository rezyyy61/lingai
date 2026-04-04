<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'resource_type' => \App\Enums\LessonResourceType::class,
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

    public function hasProcessableOriginalText(): bool
    {
        return trim((string) $this->original_text) !== '';
    }

    public function spokenAudioSegments(): array
    {
        $segments = data_get($this->analysis_meta, 'audio_script.spoken_segments');

        if (! is_array($segments)) {
            return [];
        }

        $normalized = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $type = trim((string) ($segment['type'] ?? ''));
            $speaker = trim((string) ($segment['speaker'] ?? ''));
            $style = trim((string) ($segment['style'] ?? ''));
            $text = trim((string) ($segment['text'] ?? ''));
            $pause = $segment['pause_ms'] ?? null;

            if ($type === '' || $speaker === '' || $style === '' || $text === '' || ! is_numeric($pause)) {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'speaker' => $speaker,
                'style' => $style,
                'pause_ms' => max(0, (int) $pause),
                'text' => $text,
            ];
        }

        return $normalized;
    }

    public function hasSpokenAudioSegments(): bool
    {
        return $this->spokenAudioSegments() !== [];
    }

    public function spokenAudioTranscript(): string
    {
        $segments = $this->spokenAudioSegments();

        if ($segments !== []) {
            return trim(implode("\n\n", array_map(
                fn (array $segment) => $segment['text'],
                $segments,
            )));
        }

        return trim((string) data_get($this->analysis_meta, 'audio_script.spoken_script'));
    }
}
