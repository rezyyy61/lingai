<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportLessonSentencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'replace_existing' => ['sometimes', 'boolean'],
            'sentences' => ['required', 'array', 'min:1', 'max:500'],
            'sentences.*.text' => ['required', 'string'],
            'sentences.*.translation' => ['nullable', 'string'],
            'sentences.*.source' => ['nullable', Rule::in(['original', 'generated'])],
            'sentences.*.start_time' => ['nullable', 'integer', 'min:0'],
            'sentences.*.end_time' => ['nullable', 'integer', 'min:0'],
            'sentences.*.meta' => ['nullable', 'array'],
        ];
    }
}
