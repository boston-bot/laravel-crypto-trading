<?php

namespace App\Providers;

use App\Contracts\StrategyEngine;
use App\Services\Research\DatabaseStrategyEngineAdapter;
use App\Services\Research\LegacyPhpStrategyEngineAdapter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(StrategyEngine::class, function ($app): StrategyEngine {
            return match ((string) config('research.engine.driver', 'database')) {
                'database', 'python' => $app->make(DatabaseStrategyEngineAdapter::class),
                default => $app->make(LegacyPhpStrategyEngineAdapter::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
