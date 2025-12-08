<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class LanguageController extends Controller
{
    public function index()
    {
        $supported = config('learning_languages.supported', []);

        $items = [];

        foreach ($supported as $code => $meta) {
            $items[] = [
                'code' => $code,
                'label' => $meta['label'] ?? $code,
                'native' => $meta['native'] ?? $code,
                'direction' => $meta['direction'] ?? 'ltr',
            ];
        }

        return response()->json($items);
    }
}
