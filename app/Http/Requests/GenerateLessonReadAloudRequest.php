<?php

namespace App\Http\Requests;

use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GenerateLessonReadAloudRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson
            && (int) $lesson->user_id === (int) optional($this->user())->id;
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $lesson = $this->route('lesson');

                if (! $lesson instanceof Lesson) {
                    return;
                }

                if (! $lesson->hasProcessableOriginalText()) {
                    $validator->errors()->add('lesson', 'Lesson original text is required before generating read-aloud audio.');
                }
            },
        ];
    }
}
