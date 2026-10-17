<?php

namespace App\Providers;

use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\RobinhoodGateway;
use App\Services\Research\BrokerMarketData;
use App\Services\Research\MarketDataProvider;
use App\Services\Research\OpenAiResearchModel;
use App\Services\Research\ResearchModel;
use App\Support\Mcp\DatabaseReadiness;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BrokerGateway::class, RobinhoodGateway::class);
        $this->app->bind(MarketDataProvider::class, BrokerMarketData::class);
        $this->app->bind(ResearchModel::class, OpenAiResearchModel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('mcp-connect', fn ($request) => Limit::perMinute(3)->by((string) $request->user()?->getAuthIdentifier()));
        RateLimiter::for('mcp-renew', fn ($request) => Limit::perMinute(1)->by((string) $request->user()?->getAuthIdentifier()));
        Gate::define('operate-finances', fn ($user) => in_array($user->email, config('mcp.operator_emails'), true));
        Gate::define('approve-decisions', fn ($user) => in_array($user->email, config('mcp.operator_emails'), true) && in_array($user->email, config('mcp.approver_emails'), true));
        Event::listen(CommandStarting::class, function ($event): void {
            if (str_starts_with((string) $event->command, 'migrate')) {
                $connection = $event->input->hasOption('database') ? $event->input->getOption('database') : null;
                if (DB::connection($connection)->getDriverName() === 'pgsql') {
                    // Validate the selected connection, including --database, before any DDL.
                    $original = config('database.default');
                    try {
                        config(['database.default' => $connection ?: $original]);
                        app(DatabaseReadiness::class)->inspect();
                    } finally {
                        config(['database.default' => $original]);
                    }
                }
            }
        });
    }
}
