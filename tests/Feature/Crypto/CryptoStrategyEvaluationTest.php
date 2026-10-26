<?php

namespace Tests\Feature\Crypto;

use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\InstrumentPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategySignal;
use App\Models\PaperTrading\SymbolProfile;
use App\Models\User;
use App\Services\Crypto\CryptoMarketCollector;
use App\Services\Crypto\CryptoParameters;
use App\Services\Crypto\CryptoPolicies;
use App\Services\Crypto\CryptoStrategyEvaluation;
use App\Services\Crypto\CryptoStrategyInputs;
use App\Services\PaperTrading\SignalPaperHandoff;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CoinbasePublicFake;
use Tests\Support\SignalExecutionFixture;
use Tests\TestCase;

class CryptoStrategyEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22T23:44:29.123456Z'));
        $this->owner = User::factory()->create();
        config(['mcp.operator_emails' => [$this->owner->email], 'crypto.strategy_evaluation_enabled' => true,
            'crypto.market_data_enabled' => true, 'crypto.coinbase_products' => ['BTC-USD']]);
    }

    private function cost(): array
    {
        return ['version' => 'test_observed_fee_v1', 'product_id' => 'BTC-USD', 'maker_rate' => '0.004', 'taker_rate' => '0.006',
            'cost_scope' => 'product_all_in',
            'observed_at' => now()->subMinute()->toISOString(), 'valid_until' => now()->addHour()->toISOString(), 'source' => 'Explicit fake account fee observation'];
    }

    private function policy(bool $costs = true): InstrumentPolicy
    {
        return app(CryptoPolicies::class)->apply($this->owner->id, (string) Str::uuid(), costs: $costs ? [$this->cost()] : []);
    }

    private function market(): void
    {
        CoinbasePublicFake::install(['BTC-USD']);
        app(CryptoMarketCollector::class)->tick();
        DB::table('mcp_crypto_product_snapshots')->update(['quote_volume_24h' => '50000000']);
        DB::table('mcp_crypto_quotes')->update(['bid' => '99.69', 'ask' => '99.70']);
        $end = CarbonImmutable::now()->utc()->startOfDay();
        foreach (DB::table('mcp_crypto_candles')->where('granularity', 'ONE_DAY')->where('starts_at', '>=', CryptoMarketCollector::time($end->subDays(200)))->orderBy('starts_at')->get() as $i => $row) {
            $close = $i < 180 ? Decimal::from('60')->plus(Decimal::from((string) $i)->multipliedBy(Decimal::from('0.2'))) :
                Decimal::from('99')->plus(Decimal::from((string) ($i - 180))->multipliedBy(Decimal::from('0.01')));
            DB::table('mcp_crypto_candles')->where('id', $row->id)->update(['open' => (string) $close, 'close' => (string) $close,
                'low' => (string) $close->minus(Decimal::from('0.5')), 'high' => (string) $close->plus(Decimal::from('0.5'))]);
        }
        Http::fake(fn () => throw new \RuntimeException('Evaluation must use stored data only.'));
    }

    public function test_policy_creates_one_separate_virtual_ledger_and_retries_do_not_recredit_cash(): void
    {
        $uuid = (string) Str::uuid();
        $first = app(CryptoPolicies::class)->apply($this->owner->id, $uuid);
        $repeat = app(CryptoPolicies::class)->apply($this->owner->id, $uuid);
        $this->assertSame($first->id, $repeat->id);
        $portfolio = Portfolio::findOrFail($first->paper_portfolio_id);
        $this->assertSame(['crypto', 'coinbase', null, $this->owner->id], [$portfolio->asset_class, $portfolio->venue, $portfolio->mcp_connection_id, $portfolio->actor_id]);
        $this->assertSame('400.000000000000000000', $portfolio->cash);
        $this->assertDatabaseCount('mcp_paper_cash_entries', 1);
        $this->assertDatabaseCount('mcp_paper_instrument_policies', 1);
        $next = app(CryptoPolicies::class)->apply($this->owner->id, (string) Str::uuid(), 1, [$this->cost()]);
        $this->assertSame(2, $next->version);
        $this->assertSame(CryptoPolicies::OWNER, $next->payload['owner']);
        $this->assertDatabaseCount('mcp_paper_cash_entries', 1);
        $this->assertStringNotContainsString('crypto_paper_policy', DB::table('mcp_paper_instrument_policies')->value('payload'));
        $this->assertSame('400.000000000000000000', CashEntry::first()->amount);
    }

    public function test_retries_with_changed_fees_and_stale_policy_versions_are_refused(): void
    {
        $uuid = (string) Str::uuid();
        app(CryptoPolicies::class)->apply($this->owner->id, $uuid);
        foreach ([fn () => app(CryptoPolicies::class)->apply($this->owner->id, $uuid, costs: [$this->cost()]),
            fn () => app(CryptoPolicies::class)->apply($this->owner->id, (string) Str::uuid(), 0)] as $write) {
            try {
                $write();
                $this->fail('Changed request or stale version accepted.');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $this->assertDatabaseCount('mcp_paper_cash_entries', 1);
    }

    public function test_stored_evaluation_uses_shared_rules_and_records_immutable_profile_signal_and_provenance(): void
    {
        $policy = $this->policy();
        $this->market();
        $report = app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id);
        $signal = StrategySignal::firstOrFail();
        $this->assertSame('complete', $report['status']);
        $this->assertSame(['signal', 'BUY', 'breakout_trigger_crossed'], [$signal->status, $signal->side, $signal->reason]);
        $this->assertSame(['crypto', 'coinbase', null, CryptoParameters::VERSION], [$signal->asset_class, $signal->venue, $signal->mcp_connection_id, $signal->parameters_version]);
        $this->assertFalse($signal->payload['broker_executable']);
        // History is pinned once per UTC day on the profile; each signal references it with its own quote and product row.
        $this->assertCount(200, SymbolProfile::first()->payload['evidence']['history_ids']);
        $this->assertSame(SymbolProfile::first()->input_fingerprint, $signal->payload['evidence']['profile_fingerprint']);
        $this->assertArrayNotHasKey('history_ids', $signal->payload['evidence']['input_ids']);
        $this->assertSame($signal->input_fingerprint, hash('sha256', json_encode($signal->payload['evidence'], JSON_THROW_ON_ERROR)));
        $this->assertSame('complete', SymbolProfile::first()->status);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        $this->assertDatabaseCount('mcp_paper_sim_events', 0);
        $this->assertDatabaseCount('broker_orders', 0);
        $this->assertSame(1, Portfolio::first()->ledger_version);
        $repeat = app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id);
        $this->assertSame($report, $repeat);
        $this->assertDatabaseCount('mcp_strategy_cycles', 1);
        $this->assertDatabaseCount('mcp_strategy_signals', 1);
        $this->assertDatabaseCount('mcp_symbol_profiles', 1);
        $this->expectException(\LogicException::class);
        $signal->update(['reason' => 'rewritten']);
    }

    public function test_older_policy_cost_assumptions_require_a_new_explicit_policy_before_evaluation(): void
    {
        $policy = $this->policy();
        $old = $policy->payload;
        $old['version'] = 'crypto_paper_policy_v1';
        InstrumentPolicy::create(['paper_portfolio_id' => $policy->paper_portfolio_id, 'actor_id' => $this->owner->id,
            'version' => 2, 'request_id' => (string) Str::uuid(), 'payload' => $old, 'applied_at' => now(),
            'fingerprint' => hash('sha256', json_encode($old, JSON_THROW_ON_ERROR))]);
        try {
            app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id);
            $this->fail('Older policy assumptions were evaluated with newer cost rules.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('mcp_strategy_cycles', 0);
        $this->assertDatabaseCount('mcp_strategy_signals', 0);
        $this->assertDatabaseCount('mcp_paper_cash_entries', 1);
    }

    public function test_unknown_or_expired_fees_and_unvalued_holdings_do_not_generate_entries(): void
    {
        $policy = $this->policy(false);
        $this->market();
        app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id);
        $this->assertSame('product_fee_schedule_unavailable_or_expired', StrategySignal::first()->reason);
        $fee = array_replace($this->cost(), ['valid_until' => now()->subSeconds(10)->toISOString()]);
        app(CryptoPolicies::class)->apply($this->owner->id, (string) Str::uuid(), 1, [$fee]);
        app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id);
        $this->assertSame('product_fee_schedule_unavailable_or_expired', StrategySignal::latest('id')->first()->reason);
        app(CryptoPolicies::class)->apply($this->owner->id, (string) Str::uuid(), 2, [$this->cost()]);
        // A holding without a fresh stored quote leaves equity unknown, never valued at zero.
        SimulationPosition::create(['paper_portfolio_id' => $policy->paper_portfolio_id, 'symbol' => 'SOL-USD', 'sleeve' => 'active',
            'quantity' => '1', 'reserved_quantity' => '0', 'cost_basis' => '10', 'realized_pnl' => '0']);
        app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id);
        $this->assertSame('portfolio_valuation_unavailable', StrategySignal::where('symbol', 'BTC-USD')->latest('id')->first()->reason);
    }

    public function test_quote_age_uses_microseconds_and_future_retrievals_are_not_visible(): void
    {
        $this->market();
        $now = CarbonImmutable::now();
        $params = CryptoParameters::current()['values'];
        DB::table('mcp_crypto_quotes')->update(['source_at' => CryptoMarketCollector::time($now->subSeconds(120))]);
        $this->assertSame('eligible', app(CryptoStrategyInputs::class)->read('BTC-USD', $now, $params)['status']);
        DB::table('mcp_crypto_quotes')->update(['source_at' => CryptoMarketCollector::time($now->subSeconds(120)->subMicrosecond())]);
        $this->assertContains('quote_unavailable_or_stale', app(CryptoStrategyInputs::class)->read('BTC-USD', $now, $params)['reasons']);
        DB::table('mcp_crypto_quotes')->update(['source_at' => CryptoMarketCollector::time($now), 'retrieved_at' => CryptoMarketCollector::time($now->addMicrosecond())]);
        $this->assertContains('quote_unavailable_or_stale', app(CryptoStrategyInputs::class)->read('BTC-USD', $now, $params)['reasons']);
    }

    public function test_gaps_missing_benchmark_conflicts_and_unknown_volume_fail_closed(): void
    {
        $this->market();
        $now = CarbonImmutable::now();
        $p = CryptoParameters::current()['values'];
        $old = DB::table('mcp_crypto_candles')->where('granularity', 'ONE_DAY')->orderByDesc('starts_at')->first();
        DB::table('mcp_crypto_candles')->where('id', $old->id)->delete();
        $report = app(CryptoStrategyInputs::class)->read('BTC-USD', $now, $p);
        $this->assertContains('daily_history_missing_or_discontinuous', $report['reasons']);
        $this->assertSame([], $report['bars']);
        $this->assertContains('benchmark_history_missing_or_discontinuous', app(CryptoStrategyInputs::class)->read('BTC-USD', $now, array_replace($p, ['benchmark_symbol' => 'ETH-USD']))['reasons']);
        // Settling five-minute candles and other products' daily revisions do not block; a daily revision in the window does.
        $revise = function (string $product, string $granularity) {
            $candle = DB::table('mcp_crypto_candles')->where('granularity', $granularity)->orderByDesc('starts_at')->first();
            DB::table('mcp_crypto_candle_revisions')->insert(['mcp_crypto_market_read_id' => $candle->mcp_crypto_market_read_id, 'candle_id' => $candle->id,
                'venue' => 'coinbase', 'product_id' => $product, 'granularity' => $granularity, 'starts_at' => $candle->starts_at, 'open' => '1', 'high' => '1',
                'low' => '1', 'close' => '1', 'volume' => '1', 'fingerprint' => hash('sha256', $product.$granularity), 'retrieved_at' => $candle->retrieved_at]);
        };
        $revise('BTC-USD', 'FIVE_MINUTE');
        $revise('ETH-USD', 'ONE_DAY');
        $this->assertNotContains('candle_conflict_requires_review', app(CryptoStrategyInputs::class)->read('BTC-USD', $now, $p)['reasons']);
        $revise('BTC-USD', 'ONE_DAY');
        DB::table('mcp_crypto_product_snapshots')->update(['quote_volume_24h' => null]);
        $reasons = app(CryptoStrategyInputs::class)->read('BTC-USD', $now, $p)['reasons'];
        $this->assertContains('quote_volume_unknown', $reasons);
        $this->assertContains('candle_conflict_requires_review', $reasons);
    }

    public function test_disabled_gate_and_actor_scope_prevent_cross_account_evaluation(): void
    {
        $policy = $this->policy();
        config(['crypto.strategy_evaluation_enabled' => false]);
        $this->assertSame(['status' => 'disabled'], app(CryptoStrategyEvaluation::class)->evaluate(999, 999));
        $this->assertDatabaseCount('mcp_strategy_cycles', 0);
        config(['crypto.strategy_evaluation_enabled' => true]);
        $other = User::factory()->create();
        config(['mcp.operator_emails' => [$other->email]]);
        $this->expectException(ModelNotFoundException::class);
        app(CryptoStrategyEvaluation::class)->evaluate($other->id, $policy->paper_portfolio_id);
    }

    public function test_failed_evaluation_rolls_back_all_evidence_and_retry_can_complete(): void
    {
        $policy = $this->policy();
        $this->market();
        $real = app(CryptoStrategyInputs::class);
        $this->mock(CryptoStrategyInputs::class, fn ($mock) => $mock->shouldReceive('read')->once()->andThrow(new \RuntimeException('fixture failure')));
        try {
            app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id);
            $this->fail('Failure was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('fixture failure', $e->getMessage());
        }
        $this->assertDatabaseCount('mcp_strategy_cycles', 0);
        $this->assertDatabaseCount('mcp_strategy_signals', 0);
        app()->instance(CryptoStrategyInputs::class, $real);
        $this->assertSame('complete', app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id)['status']);
    }

    public function test_commands_require_operator_arguments_and_do_not_activate_any_flags(): void
    {
        $this->artisan('mcp:crypto-policy')->assertFailed();
        $this->artisan('mcp:crypto-policy', ['--actor' => $this->owner->id, '--request' => (string) Str::uuid()])->assertSuccessful();
        $this->artisan('mcp:crypto-evaluate', ['--actor' => $this->owner->id, '--portfolio' => Portfolio::first()->id])->assertSuccessful();
        $this->assertFalse(config('broker.coinbase.writes_enabled'));
        $this->assertFalse(config('broker.robinhood.writes_enabled'));
    }

    public function test_fee_import_rejects_policy_injection_unquoted_rates_and_future_or_overlong_observations(): void
    {
        foreach ([['taker_rate' => 0.006], ['taker_rate' => '-0.1'], ['taker_rate' => '1.01'], ['mode' => 'live'],
            ['cost_scope' => null], ['cost_scope' => 'account_tier'],
            ['observed_at' => '2026-10-22 23:40:00'], ['valid_until' => 'tomorrow'],
            ['observed_at' => now()->addSecond()->toISOString()], ['valid_until' => now()->addDays(2)->toISOString()]] as $change) {
            try {
                app(CryptoPolicies::class)->apply($this->owner->id, (string) Str::uuid(), costs: [array_replace($this->cost(), $change)]);
                $this->fail('Invalid fee input was accepted.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $this->assertDatabaseCount('mcp_paper_portfolios', 0);
        $this->assertDatabaseCount('mcp_paper_cash_entries', 0);
    }

    public function test_venue_status_spread_liquidity_and_metadata_age_are_independent_entry_gates(): void
    {
        $this->market();
        $p = CryptoParameters::current()['values'];
        foreach ([['quote_volume_24h' => '9999999.99'], ['trading_disabled' => true], ['quote_currency' => 'EUR'], ['base_currency' => 'ETH'],
            ['product_type' => 'FUTURE'], ['retrieved_at' => CryptoMarketCollector::time(CarbonImmutable::now()->subSeconds(901))]] as $change) {
            $row = (array) DB::table('mcp_crypto_product_snapshots')->first();
            DB::table('mcp_crypto_product_snapshots')->update($change);
            $this->assertSame('unavailable', app(CryptoStrategyInputs::class)->read('BTC-USD', CarbonImmutable::now(), $p)['status']);
            DB::table('mcp_crypto_product_snapshots')->where('id', $row['id'])->update($row);
        }
        DB::table('mcp_crypto_quotes')->update(['ask' => '105']);
        $this->assertContains('spread_too_wide', app(CryptoStrategyInputs::class)->read('BTC-USD', CarbonImmutable::now(), $p)['reasons']);
    }

    public function test_history_must_be_known_at_cutoff_and_retry_keeps_the_first_window_inputs(): void
    {
        $policy = $this->policy();
        $this->market();
        $now = CarbonImmutable::now();
        $p = CryptoParameters::current()['values'];
        $last = DB::table('mcp_crypto_candles')->where('granularity', 'ONE_DAY')->latest('starts_at')->first();
        DB::table('mcp_crypto_candles')->where('id', $last->id)->update(['retrieved_at' => CryptoMarketCollector::time($now->addMicrosecond())]);
        $this->assertContains('daily_history_missing_or_discontinuous', app(CryptoStrategyInputs::class)->read('BTC-USD', $now, $p)['reasons']);
        DB::table('mcp_crypto_candles')->where('id', $last->id)->update(['retrieved_at' => $last->retrieved_at]);
        $first = app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id);
        $fingerprint = StrategySignal::first()->input_fingerprint;
        DB::table('mcp_crypto_quotes')->update(['ask' => '105']);
        $this->assertSame($first, app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id));
        $this->assertSame($fingerprint, StrategySignal::first()->input_fingerprint);
        config(['crypto.coinbase_products' => ['BTC-USD', 'ETH-USD']]);
        $this->expectException(HttpException::class);
        app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id);
    }

    public function test_crypto_candidate_cannot_cross_the_existing_equity_paper_handoff(): void
    {
        $policy = $this->policy();
        $this->market();
        app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id);
        $signal = StrategySignal::firstOrFail();
        [$connection] = SignalExecutionFixture::seed();
        try {
            app(SignalPaperHandoff::class)->process($connection, $connection->user_id, $signal->id);
            $this->fail('Crypto candidate crossed an equity paper boundary.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
            $this->assertDatabaseCount('mcp_paper_sim_events', 0);
            $this->assertDatabaseCount('broker_orders', 0);
        }
    }
}
