<?php

namespace Ubxty\AzureAi;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AzureAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AzureManager::class, function ($app) {
            return new AzureManager($app['config']->get('core-ai.azure_ai', []));
        });

        $this->app->alias(AzureManager::class, 'azure-ai');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\ChatCommand::class,
                Commands\ConfigureCommand::class,
                Commands\ModelsCommand::class,
                Commands\TestCommand::class,
                Commands\DefaultModelCommand::class,
            ]);
        }

        $this->registerHealthCheckRoute();
    }

    protected function registerHealthCheckRoute(): void
    {
        $config = $this->app['config']->get('core-ai.azure_ai.health_check', []);

        if (! ($config['enabled'] ?? false)) {
            return;
        }

        $path = $config['path'] ?? '/health/azure-openai';
        $middleware = $config['middleware'] ?? [];

        Route::get($path, Http\HealthCheckController::class)
            ->middleware($middleware)
            ->name('azure-ai.health');
    }
}
