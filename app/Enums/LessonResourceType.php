<?php

namespace App\Enums;

enum LessonResourceType: string
{
    case Video = 'video';
    case Text = 'text';
    case TextAi = 'text_ai';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
