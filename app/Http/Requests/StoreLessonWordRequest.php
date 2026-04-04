<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLessonWordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'term' => ['required', 'string', 'max:255'],
            'lemma' => ['nullable', 'string', 'max:255'],
            'phonetic' => ['nullable', 'string', 'max:255'],
            'part_of_speech' => ['nullable', 'string', 'max:50'],
            'meaning' => ['nullable', 'string'],
            'example_sentence' => ['nullable', 'string'],
            'translation' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
