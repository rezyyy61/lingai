<?php

namespace App\Providers;

use App\Services\Speech\Contracts\TextToSpeechInterface;
use App\Services\Speech\TextToSpeechManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TextToSpeechInterface::class, function ($app) {
            return $app->make(TextToSpeechManager::class)->providerFor('practice_shadowing');
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
