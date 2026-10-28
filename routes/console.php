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
use App\Services\Crypto\CryptoMarketCollector;
use App\Services\Monitoring\MarketCalendarSync;
use App\Services\Monitoring\MonitoringScheduler;
use App\Services\PaperTrading\BatchMarketInputCollector;
use App\Services\PaperTrading\DecisionScheduler;
use App\Services\PaperTrading\EarningsCalendar;
use App\Services\PaperTrading\IntradaySymbolWarmup;
use App\Services\PaperTrading\StrategyWatchlists;
use App\Services\PaperTrading\SymbolProfiles;
use App\Services\Research\ResearchScheduler;
use App\Services\Research\ResearchSummaries;
use App\Support\Mcp\DatabaseReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

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

// The combined forward-paper cycle owns collection when all three gates are on.
if (! config('mcp.live_automation_enabled') && config('paper.market_collection_enabled') && config('paper.market_inputs_enabled') &&
    ! (config('paper.batch_collection_enabled') && config('paper.signals_enabled') && config('paper.signal_handoff_enabled') && config('paper.real_data_fills_enabled'))) {
    Schedule::command(config('paper.batch_collection_enabled') ? 'mcp:market-cycle --once --scheduled' : 'mcp:paper-collect --once --scheduled')->everyFiveMinutes()->withoutOverlapping(10);
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

// Signals consume stored observations only; stale or missing reads remain unavailable.
if (config('paper.signals_enabled') && ! config('paper.signal_handoff_enabled')) {
    Schedule::command('mcp:strategy-cycle --once')->everyFiveMinutes()->withoutOverlapping(5);
}

if (config('paper.watchlist_intake_enabled')) {
    Schedule::command('mcp:strategy-watchlist --once')->everyFiveMinutes()->withoutOverlapping(5);
}

if (! config('mcp.live_automation_enabled') && config('paper.signals_enabled') && config('paper.signal_handoff_enabled') && config('paper.real_data_fills_enabled')) {
    Schedule::command('mcp:signal-paper-cycle --once')->everyFiveMinutes()->withoutOverlapping(5);
}

if (config('mcp.live_automation_enabled')) {
    // Follow the watchlist/monitoring slot by a minute; never hold up heartbeat and monitoring dispatch.
    Schedule::command('mcp:live-cycle --once')->cron('1-59/5 * * * *')->runInBackground()->withoutOverlapping(10);
}

Artisan::command('mcp:symbol-warmup {--once} {--account= : Local operator connection ID}', function (): int {
    if (! $this->option('once') || ($this->option('account') !== null && ! ctype_digit($this->option('account')))) {
        $this->error('Use --once and an optional numeric --account.');

        return 1;
    }
    $result = app(IntradaySymbolWarmup::class)->tick($this->option('account') === null ? null : (int) $this->option('account'));
    $this->line(json_encode($result, JSON_THROW_ON_ERROR));

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Bounded regular-session reference and profile warm-up; no signals, orders or policy changes');

if (config('paper.intraday_warmup_enabled')) {
    Schedule::command('mcp:symbol-warmup --once')->everyFiveMinutes()->runInBackground()->withoutOverlapping(10);
}

// Public SEC filings change slowly; one daily refresh keeps stored classifications inside their seven-day validity.
if (config('sec_edgar.enabled') && config('paper.market_collection_enabled')) {
    Schedule::command('mcp:instrument-classification --scheduled')->dailyAt('06:20')->timezone('America/New_York')->withoutOverlapping(60);
}

Artisan::command('mcp:market-calendar', function (): int {
    if (! config('alpaca_data.enabled') || ! config('alpaca_data.calendar_enabled')) {
        $this->error('Market calendar reads are disabled.');

        return 1;
    }
    $outcome = app(MarketCalendarSync::class)->sync();
    $this->line(json_encode($outcome, JSON_THROW_ON_ERROR));

    return $outcome['error'] === null && $outcome['conflicts'] === [] ? 0 : 1;
})->purpose('Refresh stored XNYS sessions from a complete read-only provider calendar; conflicting sessions are reported, not overwritten');

// Session data expires after seven days; a daily refresh keeps the next sixty days known.
if (config('alpaca_data.enabled') && config('alpaca_data.calendar_enabled')) {
    Schedule::command('mcp:market-calendar')->dailyAt('06:10')->timezone('America/New_York')->withoutOverlapping(30);
}

// Listing and split-adjusted daily history refresh once a day, after overnight provider updates and before the open.
if (config('alpaca_data.enabled') && config('alpaca_data.reference_enabled') && config('paper.market_collection_enabled')) {
    Schedule::command('mcp:market-reference --scheduled')->dailyAt('06:40')->timezone('America/New_York')->withoutOverlapping(60);
}

Artisan::command('mcp:symbol-profiles {--account= : Local operator-owned connection ID} {--symbol=* : Symbols in the selected universe; default all} {--scheduled}', function (): int {
    if ($this->option('scheduled')) {
        $this->line('Recorded symbol profiles: '.app(SymbolProfiles::class)->tick());

        return 0;
    }
    $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
    $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->whereNotNull('bound_account_id')->first();
    if (! $connection || ! config('paper.symbol_profiles_enabled')) {
        $this->error($connection ? 'Symbol profiles are disabled.' : 'Select an operator-owned bound connection.');

        return 1;
    }
    foreach (app(SymbolProfiles::class)->record($connection, $connection->user_id, $this->option('symbol'), (string) Str::uuid()) as $profile) {
        // Facts only; indicator values and history stay in the encrypted record.
        $this->line(json_encode(['symbol' => $profile->symbol, 'session_date' => $profile->session_date?->toDateString(), 'status' => $profile->status,
            'setup' => $profile->setup, 'reason' => $profile->reason, 'entry_blockers' => $profile->payload['entry_blockers'] ?? [],
            'levels' => $profile->payload['levels'] ?? null], JSON_THROW_ON_ERROR));
    }

    return 0;
})->purpose('Record daily symbol profiles and playbook assignments from stored history; no network, order or policy change');

Artisan::command('mcp:earnings-calendar {--account= : Local operator-owned connection ID} {--scheduled}', function (): int {
    if ($this->option('scheduled')) {
        $this->line('Recorded earnings calendars: '.app(EarningsCalendar::class)->tick());

        return 0;
    }
    $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
    $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->whereNotNull('bound_account_id')->first();
    if (! $connection || ! config('robinhood_mcp.earnings_enabled')) {
        $this->error($connection ? 'Earnings calendar reads are disabled.' : 'Select an operator-owned bound connection.');

        return 1;
    }
    $read = app(EarningsCalendar::class)->collect($connection, $connection->user_id, (string) Str::uuid());
    $this->line(json_encode(['status' => $read->status, 'reason' => $read->reason, 'start_date' => $read->start_date->toDateString(),
        'days' => $read->days, 'events' => $read->event_count, 'retrieved_at' => $read->retrieved_at->toIso8601String()], JSON_THROW_ON_ERROR));

    return $read->status === 'complete' ? 0 : 1;
})->purpose('Record the read-only market-wide Robinhood earnings calendar (31 days); no order, account or policy change');

// Earnings dates refresh before the 06:50 profiles, which apply the entry blackout.
if (config('robinhood_mcp.earnings_enabled') && config('paper.market_collection_enabled')) {
    Schedule::command('mcp:earnings-calendar --scheduled')->dailyAt('06:30')->timezone('America/New_York')->withoutOverlapping(30);
}

// Profiles follow the 06:40 reference refresh; they read stored history only.
if (config('paper.symbol_profiles_enabled')) {
    Schedule::command('mcp:symbol-profiles --scheduled')->dailyAt('06:50')->timezone('America/New_York')->withoutOverlapping(30);
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

Artisan::command('mcp:market-cycle {--once} {--scheduled} {--account= : Local connection ID}', function (): int {
    if (! $this->option('once') || ($this->option('account') !== null && ! ctype_digit($this->option('account')))) {
        $this->error('Use --once and an optional numeric --account.');

        return 1;
    }
    $result = app(BatchMarketInputCollector::class)->tick($this->option('account') === null ? null : (int) $this->option('account'), (bool) $this->option('scheduled'));
    $this->line(json_encode($result, JSON_THROW_ON_ERROR));

    return 0;
})->purpose('Read-only batched quote and exact-symbol evidence collection; separate default-off gate, no signals or orders');

Artisan::command('mcp:crypto-market {--once} {--scheduled}', function (): int {
    if (! $this->option('once')) {
        $this->error('Use --once; long-running work belongs to the scheduler.');

        return 1;
    }
    $result = app(CryptoMarketCollector::class)->tick((bool) $this->option('scheduled'));
    $this->line(json_encode($result, JSON_THROW_ON_ERROR));

    return in_array($result['status'], ['complete', 'disabled', 'busy'], true) ? 0 : 1;
})->purpose('Crypto C1: read-only Coinbase public market data (products, best bid/ask, closed candles); no credentials, signals or orders');

// Crypto runs 24/7, independent of the equity session calendar.
if (config('crypto.market_data_enabled')) {
    if (config('crypto.paper_enabled')) {
        // The paper cycle owns collection so each evaluation follows a fresh read; no legacy crypto job is involved.
        Schedule::command('mcp:crypto-paper --once --scheduled')->everyFiveMinutes()->runInBackground()->withoutOverlapping(10);
    } else {
        Schedule::command('mcp:crypto-market --once --scheduled')->everyFiveMinutes()->withoutOverlapping(10);
    }
}
if (config('crypto.spread_study_enabled')) {
    Schedule::command('mcp:crypto-spreads --once')->cron('3-58/5 * * * *')->withoutOverlapping(5);
}
if (config('crypto.fee_refresh_enabled')) {
    Schedule::command('mcp:crypto-fees --scheduled')->hourlyAt(7)->withoutOverlapping(10);
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
