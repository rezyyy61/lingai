<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportLessonWordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'replace_existing' => ['sometimes', 'boolean'],
            'words' => ['required', 'array', 'min:1', 'max:500'],
            'words.*.term' => ['required', 'string', 'max:255'],
            'words.*.lemma' => ['nullable', 'string', 'max:255'],
            'words.*.phonetic' => ['nullable', 'string', 'max:255'],
            'words.*.part_of_speech' => ['nullable', 'string', 'max:50'],
            'words.*.meaning' => ['nullable', 'string'],
            'words.*.example_sentence' => ['nullable', 'string'],
            'words.*.translation' => ['nullable', 'string', 'max:255'],
            'words.*.meta' => ['nullable', 'array'],
        ];
    }
}
