<?php

namespace App\Providers;

use App\Models\McpConnection;
use App\Models\Monitoring\Incident;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\RobinhoodGateway;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\DecisionInputs;
use App\Services\PaperTrading\StoredDecisionInputs;
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
use Illuminate\Support\Facades\View;
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
        $this->app->bind(DecisionInputs::class, StoredDecisionInputs::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('mcp-connect', fn ($request) => Limit::perMinute(3)->by((string) $request->user()?->getAuthIdentifier()));
        RateLimiter::for('mcp-renew', fn ($request) => Limit::perMinute(1)->by((string) $request->user()?->getAuthIdentifier()));
        // Operator-triggered broker reads; a link from another site cannot spend the broker's rate limit freely.
        RateLimiter::for('mcp-broker-read', fn ($request) => Limit::perMinute(10)->by((string) $request->user()?->getAuthIdentifier()));
        Gate::define('operate-finances', fn ($user) => in_array($user->email, config('mcp.operator_emails'), true));
        Gate::define('approve-decisions', fn ($user) => in_array($user->email, config('mcp.operator_emails'), true) && in_array($user->email, config('mcp.approver_emails'), true));
        // The console header shows the signed-in operator's own connection and open incidents when a page does not supply them.
        View::composer('layouts.console', function ($view): void {
            $data = $view->getData();
            $connection = array_key_exists('connection', $data) ? $data['connection'] : (($id = auth()->id()) ? McpConnection::where('user_id', $id)->first() : null);
            $view->with('connection', $connection);
            if (! array_key_exists('openIncidents', $data)) {
                $view->with('openIncidents', $connection?->bound_account_id ? Incident::where('mcp_connection_id', $connection->id)
                    ->where('scope', AccountScope::key($connection))->where('state', 'open')->count() : 0);
            }
        });
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
