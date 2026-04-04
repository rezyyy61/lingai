<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLessonWordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'term' => ['sometimes', 'string', 'max:255'],
            'lemma' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phonetic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'part_of_speech' => ['sometimes', 'nullable', 'string', 'max:50'],
            'meaning' => ['sometimes', 'nullable', 'string'],
            'example_sentence' => ['sometimes', 'nullable', 'string'],
            'translation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
