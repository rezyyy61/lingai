<?php

namespace App\Providers;

use App\Services\Ai\LlmClient;
use App\Services\Ai\OpenAiLlmClient;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmClient::class, OpenAiLlmClient::class);
    }
}
