<?php

namespace App\Providers;

use App\Contracts\AIProviderInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            AIProviderInterface::class,
            function () {
                $provider = strtolower((string) config('ai.provider', 'pateway'));
                $providerConfig = config("ai.providers.{$provider}", []);
                $class = $providerConfig['class'] ?? null;

                if (! $class || ! class_exists($class)) {
                    throw new \RuntimeException("AI Provider [{$provider}] is not configured or class [{$class}] does not exist.");
                }

                return app()->make($class, [
                    'apiKey' => $providerConfig['api_key'] ?? null,
                    'model' => $providerConfig['model'] ?? null,
                    'baseUrl' => $providerConfig['base_url'] ?? null,
                ]);
            }
        );
    }

    public function boot(): void
    {
        //
    }
}
