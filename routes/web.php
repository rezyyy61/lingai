<?php

use App\Http\Middleware\DebugHeaders;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});


Route::get('/debug-mw', function () {
    return 'ok';
})->middleware(DebugHeaders::class);
