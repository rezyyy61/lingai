<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLessonSentenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text' => ['sometimes', 'string'],
            'translation' => ['sometimes', 'nullable', 'string'],
            'source' => ['sometimes', 'nullable', Rule::in(['original', 'generated'])],
            'start_time' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'end_time' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
