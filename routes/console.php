<?php

use App\Enums\BrokerType;
use App\Jobs\EvaluateSignalsJob;
use App\Jobs\ReconcileBrokerFillJob;
use App\Jobs\SyncCoinbaseAccountJob;
use App\Jobs\SyncCoinbaseAssetsJob;
use App\Jobs\SyncCoinbaseMarketDataJob;
use App\Jobs\SyncCoinbaseOrdersJob;
use App\Jobs\SyncCoinbasePositionsJob;
use App\Jobs\SyncRobinhoodAccountJob;
use App\Jobs\SyncRobinhoodAssetsJob;
use App\Jobs\SyncRobinhoodMarketDataJob;
use App\Jobs\SyncRobinhoodOrdersJob;
use App\Jobs\SyncRobinhoodPositionsJob;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Monitoring\MonitoringScheduler;
use App\Services\PaperTrading\DecisionScheduler;
use App\Services\PaperTrading\StrategyWatchlists;
use App\Services\Research\ResearchScheduler;
use App\Services\Research\ResearchSummaries;
use App\Support\Mcp\DatabaseReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mcp:doctor', function (): int {
    try {
        $state = app(DatabaseReadiness::class)->inspect();
        $this->line(json_encode($state, JSON_THROW_ON_ERROR));

        return $state['migrations_present'] ? 0 : 1;
    } catch (Throwable) {
        $this->error('Database unavailable or application schema invalid. Check private database configuration.');

        return 1;
    }
})->purpose('Read-only database identity and schema placement check; no credentials are printed');

Artisan::command('mcp:operator-create {email} {name}', function (): int {
    $email = (string) $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($email, config('mcp.operator_emails'), true)) {
        $this->error('Set this email in MCP_OPERATOR_EMAILS before provisioning.');

        return 1;
    }
    $password = $this->secret('New local password (at least 12 characters)');
    if (! is_string($password) || strlen($password) < 12) {
        $this->error('Password must contain at least 12 characters.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('User already exists; no credentials changed.');

        return 1;
    }
    User::create(['email' => $email, 'name' => $this->argument('name'), 'password' => $password]);
    $this->info('Local operator created.');

    return 0;
})->purpose('Provision a local operator with a hidden password prompt; no public registration');

Artisan::command('mcp:monitor {--once}', function (): int {
    if (! $this->option('once')) {
        $this->error('Use --once; long-running work belongs to the scheduler.');

        return 1;
    }
    $this->line('Queued observations: '.app(MonitoringScheduler::class)->tick());

    return 0;
})->purpose('One deduplicated monitoring dispatch; requires monitoring enabled');

Artisan::command('mcp:research {--once} {--sync : Process in the foreground instead of queueing}', function (): int {
    if (! $this->option('once')) {
        $this->error('Use --once; long-running work belongs to the scheduler and research worker.');

        return 1;
    }
    $count = app(ResearchScheduler::class)->tick((bool) $this->option('sync'));
    $this->line(($this->option('sync') ? 'Processed' : 'Queued').' research events: '.$count);

    return 0;
})->purpose('One bounded research detection/dispatch cycle; requires research enabled and reviewed cost settings');

Artisan::command('mcp:summary {--kind=daily} {--account= : Local connection ID; defaults to all operator accounts} {--date= : New York date in a closed period, YYYY-MM-DD; defaults to the previous day or week}', function (): int {
    $kind = $this->option('kind');
    $date = $this->option('date');
    if (! in_array($kind, ['daily', 'weekly'], true) || ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date))) {
        $this->error('Use --kind=daily|weekly and an optional --date=YYYY-MM-DD.');

        return 1;
    }
    $now = CarbonImmutable::now('America/New_York');
    $at = $date ? CarbonImmutable::parse($date.' 12:00:00', 'America/New_York') : ($kind === 'daily' ? $now->subDay() : $now->subWeek());
    $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
    $connections = McpConnection::whereIn('user_id', $operators)->whereNotNull('bound_account_id')
        ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))->get();
    foreach ($connections as $connection) {
        try {
            $summary = app(ResearchSummaries::class)->generate($connection, $kind, $at);
        } catch (InvalidArgumentException) {
            $this->error('Only closed periods can be summarized; choose an earlier --date.');

            return 1;
        }
        // Identifiers and counts only; balances and source text stay in the encrypted record.
        $this->line(json_encode(['summary_id' => $summary->id, 'kind' => $summary->kind, 'period' => $summary->period], JSON_THROW_ON_ERROR));
    }

    return $connections->isEmpty() ? 1 : 0;
})->purpose('Deterministic daily/weekly summary with explicit performance and benchmark limitations; no AI call');

if (config('mcp.monitoring_enabled')) {
    Schedule::command('mcp:monitor --once')->everyMinute()->withoutOverlapping();
    Schedule::command('mcp:summary --kind=daily')->dailyAt('00:15')->timezone('America/New_York');
    Schedule::command('mcp:summary --kind=weekly')->mondays()->at('00:30')->timezone('America/New_York');
}

if (config('research.enabled')) {
    Schedule::command('mcp:research --once')->everyFiveMinutes()->withoutOverlapping();
}

Artisan::command('mcp:paper-decisions {--once} {--sync : Process the current window in the foreground}', function (): int {
    if (! $this->option('once')) {
        $this->error('Use --once; recurring work belongs to the existing scheduler.');

        return 1;
    }
    $count = app(DecisionScheduler::class)->tick((bool) $this->option('sync'));
    $this->line('Paper decision windows dispatched: '.$count);

    return 0;
})->purpose('Bounded non-executable paper proposals; requires explicit enablement, applied policy and reviewed AI configuration');

if (config('paper.market_collection_enabled') && config('paper.market_inputs_enabled')) {
    Schedule::command('mcp:paper-collect --once --scheduled')->everyFiveMinutes()->withoutOverlapping(10);
}

Artisan::command('mcp:strategy-watchlist {--once} {--account= : Local connection ID}', function (): int {
    if (! $this->option('once') || ($this->option('account') !== null && ! ctype_digit($this->option('account')))) {
        $this->error('Use --once and an optional numeric --account.');

        return 1;
    }
    try {
        $count = app(StrategyWatchlists::class)->tick($this->option('account') !== null ? (int) $this->option('account') : null);
        $this->line('Strategy watchlist reads processed: '.$count);

        return 0;
    } catch (Throwable) {
        $this->error('Watchlist intake unavailable. Check configuration, account binding and stored read evidence.');

        return 1;
    }
})->purpose('Read the configured strategy watchlist; no policy, AI, order or brokerage mutation');

if (config('paper.watchlist_intake_enabled')) {
    Schedule::command('mcp:strategy-watchlist --once')->everyFiveMinutes()->withoutOverlapping(5);
}

// Public SEC filings change slowly; one daily refresh keeps stored classifications inside their seven-day validity.
if (config('sec_edgar.enabled') && config('paper.market_collection_enabled')) {
    Schedule::command('mcp:instrument-classification --scheduled')->dailyAt('06:20')->timezone('America/New_York')->withoutOverlapping(60);
}

// Listing and split-adjusted daily history refresh once a day, after overnight provider updates and before the open.
if (config('alpaca_data.enabled') && config('alpaca_data.reference_enabled') && config('paper.market_collection_enabled')) {
    Schedule::command('mcp:market-reference --scheduled')->dailyAt('06:40')->timezone('America/New_York')->withoutOverlapping(60);
}

if (config('paper.decisions_enabled')) {
    Schedule::command('mcp:paper-decisions --once')->everyMinute()->withoutOverlapping();
}

if (config('operations.enabled')) {
    Schedule::command('mcp:health --scheduled')->everyMinute()->withoutOverlapping();
    Schedule::command('mcp:notify --once')->everyMinute()->withoutOverlapping();
    Schedule::command('mcp:notify --digest')->dailyAt('08:30')->timezone('America/New_York');
    Schedule::command('mcp:backup')->dailyAt('02:30')->timezone('America/Chicago')->withoutOverlapping();
    Schedule::command('mcp:restore-drill')->monthlyOn(1, '03:30')->timezone('America/Chicago')->withoutOverlapping();
}

if (! config('mcp.legacy_schedules_enabled')) {
    return;
}

$defaultBroker = BrokerType::default();

if ($defaultBroker === BrokerType::COINBASE) {
    Schedule::job(new SyncCoinbaseAccountJob)->everyFiveMinutes();
    Schedule::job(new SyncCoinbaseAssetsJob)->everyTenMinutes();
    Schedule::job(new SyncCoinbaseMarketDataJob(timeframe: '1d'))->everyTenMinutes();
    Schedule::job(new SyncCoinbaseMarketDataJob(timeframe: '4h'))->hourly();
    Schedule::job(new SyncCoinbasePositionsJob)->everyFiveMinutes();
    Schedule::job(new SyncCoinbaseOrdersJob)->everyFiveMinutes();
} else {
    Schedule::job(new SyncRobinhoodAccountJob)->everyFiveMinutes();
    Schedule::job(new SyncRobinhoodAssetsJob)->everyTenMinutes();
    Schedule::job(new SyncRobinhoodMarketDataJob(timeframe: '1d'))->everyTenMinutes();
    Schedule::job(new SyncRobinhoodMarketDataJob(timeframe: '4h'))->hourly();
    Schedule::job(new SyncRobinhoodPositionsJob)->everyFiveMinutes();
    Schedule::job(new SyncRobinhoodOrdersJob)->everyFiveMinutes();
}

Schedule::job(new EvaluateSignalsJob)->dailyAt('09:00');
Schedule::job(new ReconcileBrokerFillJob)->everyMinute();
