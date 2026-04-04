<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitFlashcardReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lesson_word_id' => ['required', 'integer', 'exists:lesson_words,id'],
            'result' => ['required', 'string', 'in:know,dont_know'],
        ];
    }
}
