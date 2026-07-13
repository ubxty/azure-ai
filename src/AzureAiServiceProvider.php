<?php

namespace Ubxty\AzureAi;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AzureAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/azure-ai.php', 'azure-ai');

        $this->app->singleton(AzureManager::class, function ($app) {
            return new AzureManager($app['config']->get('azure-ai', []));
        });

        $this->app->alias(AzureManager::class, 'azure-ai');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/azure-ai.php' => config_path('azure-ai.php'),
            ], 'azure-ai-config');

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
        $config = $this->app['config']->get('azure-ai.health_check', []);

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
