<?php

namespace Tests\Support;

use App\Models\PaperTrading\EarningsCalendarRead;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\RobinhoodWatchlists;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\IncidentRecorder;
use App\Services\Monitoring\MarketCalendar;
use App\Services\Monitoring\Monitor;
use App\Services\PaperTrading\PaperInstrumentClassifications;
use App\Services\PaperTrading\PaperMarketReferences;
use App\Services\PaperTrading\RuleEligibilityPolicies;
use App\Services\PaperTrading\StrategyWatchlists;
use App\Services\PaperTrading\SymbolProfiles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Synthetic source and portfolio evidence only. Never touches a real account or external service. */
final class StrategySignalFixture
{
    public static function seed(): array
    {
        [$c, $portfolio, $policy, $execution] = ExecutionFixture::seed();
        config(['paper.signals_enabled' => true, 'paper.symbol_profiles_enabled' => true, 'paper.rule_eligibility_enabled' => true,
            'paper.watchlist_intake_enabled' => true, 'robinhood_mcp.watchlists_enabled' => true, 'mcp.external_order_detection_enabled' => true,
            'paper.instrument_classification_enabled' => true, 'paper.corporate_actions_enabled' => true, 'robinhood_mcp.earnings_enabled' => true]);
        for ($day = CarbonImmutable::now()->startOfDay()->subDays(40); $day->lte(CarbonImmutable::now()->startOfDay()->addDays(15)); $day = $day->addDay()) {
            $date = $day->toDateString();
            app(MarketCalendar::class)->store($date, $day->isWeekend() ? 'holiday' : 'regular', $day->isWeekend() ? null : $date.'T13:30:00Z',
                $day->isWeekend() ? null : $date.'T20:00:00Z', 'synthetic_fixture');
        }
        $gateway = new StrategyWatchlistGateway;
        $gateway->members = [['id' => 'fixture-demo', 'object_type' => 'instrument', 'symbol' => 'DEMO']];
        $watchlists = new StrategyWatchlists(new RobinhoodWatchlists($gateway), app(IncidentRecorder::class));
        $watchlists->configure($c, $c->user_id, 0, (string) Str::uuid(), 'Strategy Candidates');
        $watchlists->collect($c, $c->user_id, (string) Str::uuid());
        EdgarFixture::enable();
        EdgarFixture::fake(EdgarFixture::plainFund());
        app(PaperInstrumentClassifications::class)->collect($c, $c->user_id, ['DEMO'], (string) Str::uuid());
        app(RuleEligibilityPolicies::class)->apply($c, $c->user_id, 0, (string) Str::uuid(), RuleEligibilityPolicies::DEFAULTS);
        AlpacaReferenceFixture::enable();
        $rising = fn ($n, $from, $step) => array_map(fn ($i) => $from + $i * $step, range(0, $n - 1));
        AlpacaReferenceFixture::fake(fn () => AlpacaReferenceFixture::body([
            'DEMO' => AlpacaReferenceFixture::series([...$rising(255, 5000, 20), ...array_fill(0, 20, 10100)]),
            'SPY' => AlpacaReferenceFixture::series($rising(275, 30000, 10)),
        ]), ['DEMO' => AlpacaReferenceFixture::asset('DEMO')]);
        app(PaperMarketReferences::class)->collect($c, $c->user_id, [], (string) Str::uuid());
        self::earnings($c);
        app(SymbolProfiles::class)->record($c, $c->user_id, ['DEMO'], (string) Str::uuid());
        app()->instance(BrokerGateway::class, new ExternalOrderGateway);
        app(Monitor::class)->sync($c->id, 'signal-fixture:'.Str::uuid());
        self::quote($c);

        return [$c, $portfolio, $policy, $execution, $watchlists, $gateway];
    }

    public static function quote($c, array $patch = [])
    {
        return PaperActionFixture::input($c, [], $patch + ['auth_generation' => $c->auth_generation,
            'quote.bid' => '101.50', 'quote.ask' => '101.60', 'instrument' => ['id' => 'synthetic-instrument', 'symbol' => 'DEMO'],
            'reference_reads.classification' => ['provider' => TrustedHttp::RESOURCE, 'complete' => true, 'source_as_of' => null,
                'retrieved_at' => now()->toISOString(), 'contract_fingerprint' => config('robinhood_mcp.operations.classification.fingerprint'),
                'fields' => ['id' => 'synthetic-instrument', 'symbol' => 'DEMO', 'asset_type' => 'etf']],
            'execution_reads.tradability.contract_fingerprint' => config('robinhood_mcp.operations.tradability.fingerprint')]);
    }

    public static function earnings($c, array $events = []): void
    {
        EarningsCalendarRead::create(['mcp_connection_id' => $c->id, 'scope' => AccountScope::key($c), 'request_id' => (string) Str::uuid(),
            'actor_id' => $c->user_id, 'start_date' => now()->toDateString(), 'days' => 15, 'status' => 'complete', 'reason' => 'earnings_calendar_recorded',
            'event_count' => count($events), 'payload' => ['events' => $events], 'retrieved_at' => now(), 'recorded_at' => now()]);
    }
}
