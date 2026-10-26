<?php

namespace Tests\Feature\Crypto;

use App\Models\BrokerCredential;
use App\Models\PaperTrading\CryptoFeeObservation;
use App\Models\PaperTrading\InstrumentPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SignalOrderHandoff;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Models\User;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Coinbase\CoinbaseJwtSigner;
use App\Services\Crypto\CryptoFeeObservations;
use App\Services\Crypto\CryptoMarketCollector;
use App\Services\Crypto\CryptoPolicies;
use App\Services\Crypto\CryptoStrategyEvaluation;
use App\Services\Operations\OperationsHealth;
use App\Services\PaperTrading\PaperFillPrice;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CoinbasePublicFake;
use Tests\TestCase;

class CryptoPaperLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CoinbasePublicFake $venue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22T12:00:10.250000Z'));
        $this->owner = User::factory()->create();
        config(['mcp.operator_emails' => [$this->owner->email], 'crypto.strategy_evaluation_enabled' => true, 'crypto.market_data_enabled' => true,
            'crypto.paper_enabled' => true, 'crypto.coinbase_products' => ['BTC-USD']]);
    }

    private function cost(string $product = 'BTC-USD'): array
    {
        return ['version' => 'test_observed_fee_v1', 'product_id' => $product, 'maker_rate' => '0.004', 'taker_rate' => '0.006', 'cost_scope' => 'product_all_in',
            'observed_at' => now()->subMinute()->toISOString(), 'valid_until' => now()->addHours(20)->toISOString(), 'source' => 'Explicit fake account fee observation'];
    }

    private function review(): array
    {
        return ['pricing_tier' => 'Intro', 'excluded_products' => ['USDT-USD', 'DAI-USD'], 'cost_scope' => 'product_all_in',
            'tax_treatment' => 'excluded_by_owner_direction', 'reviewed_at' => now()->subMinute()->toISOString(), 'source' => 'Owner signed-in fee page fixture'];
    }

    private function policy(array $products = ['BTC-USD'], ?array $review = null, int $base = 0): InstrumentPolicy
    {
        return app(CryptoPolicies::class)->apply($this->owner->id, (string) Str::uuid(), $base, $review ? [] : array_map(fn ($p) => $this->cost($p), $products), $review);
    }

    private function observation(array $change = []): CryptoFeeObservation
    {
        return CryptoFeeObservation::create(array_replace(['venue' => 'coinbase', 'actor_id' => $this->owner->id, 'trigger' => 'scheduled', 'status' => 'complete',
            'contract' => 'coinbase_spot_fee_tier_review_v1', 'pricing_tier' => 'Intro', 'maker_rate' => '0.005', 'taker_rate' => '0.009',
            'reasons' => ['product_applicability_requires_owner_review', 'tax_unknown_or_requires_all_in_review'], 'response_sha256' => str_repeat('a', 64),
            'request_started_at' => now(), 'retrieved_at' => now(), 'recorded_at' => now()], $change));
    }

    /** Collect through the fake venue, then shape a rising history whose current ask crosses the breakout trigger. */
    private function market(array $products = ['BTC-USD'], string $size = '50'): void
    {
        $this->venue = CoinbasePublicFake::install($products);
        app(CryptoMarketCollector::class)->tick();
        DB::table('mcp_crypto_product_snapshots')->update(['quote_volume_24h' => '50000000']);
        DB::table('mcp_crypto_quotes')->update(['bid' => '99.69', 'ask' => '99.70', 'bid_size' => $size, 'ask_size' => $size]);
        $end = CarbonImmutable::now()->utc()->startOfDay();
        foreach ($products as $product) {
            foreach (DB::table('mcp_crypto_candles')->where(['granularity' => 'ONE_DAY', 'product_id' => $product])->where('starts_at', '>=', CryptoMarketCollector::time($end->subDays(200)))->orderBy('starts_at')->get() as $i => $row) {
                $close = $i < 180 ? Decimal::from('60')->plus(Decimal::from((string) $i)->multipliedBy(Decimal::from('0.2'))) :
                    Decimal::from('99')->plus(Decimal::from((string) ($i - 180))->multipliedBy(Decimal::from('0.01')));
                DB::table('mcp_crypto_candles')->where('id', $row->id)->update(['open' => (string) $close, 'close' => (string) $close,
                    'low' => (string) $close->minus(Decimal::from('0.5')), 'high' => (string) $close->plus(Decimal::from('0.5'))]);
            }
        }
        // Cycles read stored data only; any later venue request would be recorded by the fake.
        $this->venue->paths = [];
    }

    /** A later successful read with a fresh product row and book for each product. */
    private function quote(string $bid, string $ask, string $size = '50', array $products = ['BTC-USD']): void
    {
        $readId = DB::table('mcp_crypto_market_reads')->insertGetId(['request_id' => (string) Str::uuid(), 'venue' => 'coinbase',
            'contract' => 'coinbase_advanced_public_market_v1', 'trigger' => 'scheduled', 'status' => 'complete', 'requested_products' => json_encode($products),
            'started_at' => CryptoMarketCollector::time(CarbonImmutable::now()), 'finished_at' => CryptoMarketCollector::time(CarbonImmutable::now())]);
        foreach ($products as $product) {
            $row = (array) DB::table('mcp_crypto_product_snapshots')->where('product_id', $product)->orderByDesc('id')->first();
            DB::table('mcp_crypto_product_snapshots')->insert(array_replace(array_diff_key($row, ['id' => 1]), ['mcp_crypto_market_read_id' => $readId,
                'retrieved_at' => CryptoMarketCollector::time(CarbonImmutable::now())]));
            DB::table('mcp_crypto_quotes')->insert(['mcp_crypto_market_read_id' => $readId, 'venue' => 'coinbase', 'product_id' => $product,
                'bid' => $bid, 'ask' => $ask, 'bid_size' => $size, 'ask_size' => $size,
                'source_at' => CryptoMarketCollector::time(CarbonImmutable::now()->subSecond()), 'retrieved_at' => CryptoMarketCollector::time(CarbonImmutable::now())]);
        }
    }

    /** SQLite stores decimal columns as binary floats; exact 18-place arithmetic is covered by the PostgreSQL suite. */
    private function assertNear(Decimal $expected, string $actual): void
    {
        $difference = $expected->minus(Decimal::from($actual));
        $this->assertTrue($difference->compareTo(Decimal::from('0.000000001')) < 0 && $difference->compareTo(Decimal::from('-0.000000001')) > 0, "$actual differs from $expected");
    }

    private function cycle(InstrumentPolicy $policy): array
    {
        return app(CryptoStrategyEvaluation::class)->evaluate($this->owner->id, $policy->paper_portfolio_id);
    }

    public function test_entry_signal_becomes_a_filled_simulated_limit_order_with_venue_fees_and_no_broker_order(): void
    {
        $policy = $this->policy();
        $this->market();
        $report = $this->cycle($policy);
        $this->assertSame(['signal', 'BUY', 'order_created'], [$report['signals'][0]['status'], $report['signals'][0]['side'], $report['signals'][0]['handoff']['status']]);
        $order = SimulationOrder::firstOrFail();
        $fill = SimulationEvent::where('kind', 'fill')->firstOrFail();
        $price = PaperFillPrice::calculate('BUY', '99.70', '10', 18);
        $this->assertSame(['filled', 'limit', '99.800000000000000000', null, $policy->id], [$order->status, $order->type, $order->price_bound, $order->policy_application_id, $order->instrument_policy_id]);
        $this->assertSame(0, Decimal::from($fill->price)->compareTo($price));
        $notional = Decimal::from($order->quantity)->multipliedBy($price);
        $fee = $notional->multipliedBy(Decimal::from('0.006'))->rounded(18, RoundingMode::CEILING);
        $portfolio = Portfolio::findOrFail($policy->paper_portfolio_id);
        $this->assertNear(Decimal::from('400')->minus($notional)->minus($fee), $portfolio->cash);
        $this->assertSame(0, Decimal::from($portfolio->reserved_cash)->compareTo(Decimal::from('0')));
        // The 25% position cap binds before the 5% risk budget at this stop distance.
        $this->assertLessThanOrEqual(0, Decimal::from($order->quantity)->multipliedBy(Decimal::from($order->price_bound))->compareTo(Decimal::from('100')));
        $this->assertSame($order->quantity, SimulationPosition::firstOrFail()->quantity);
        $this->assertSame('real_data_paper_simulation', $fill->evidence['mode']);
        $this->assertFalse(SignalOrderHandoff::firstOrFail()->payload['broker_executable']);
        $this->assertDatabaseCount('broker_orders', 0);
        $this->assertSame([], $this->venue->paths);
        // A retry in the same window returns the frozen result without a second order.
        $this->assertSame($report, $this->cycle($policy));
        $this->assertDatabaseCount('mcp_paper_sim_orders', 1);
    }

    public function test_partial_fill_continues_only_on_a_newer_quote_and_expiry_releases_the_reservation(): void
    {
        $policy = $this->policy();
        $this->market(size: '0.4');
        $this->cycle($policy);
        $order = SimulationOrder::firstOrFail();
        $this->assertSame(['partial', '0.400000000000000000'], [$order->status, $order->filled_quantity]);
        $this->assertGreaterThan(0, Decimal::from(Portfolio::first()->reserved_cash)->compareTo(Decimal::from('0')));
        $this->travel(5)->minutes();
        $this->quote('99.69', '99.70', '0.3');
        $this->cycle($policy);
        $this->assertSame('0.700000000000000000', $order->fresh()->filled_quantity);
        $this->travel(11)->minutes();
        $this->cycle($policy);
        $order->refresh();
        $this->assertSame('expired', $order->status);
        $this->assertSame(0, Decimal::from(Portfolio::first()->reserved_cash)->compareTo(Decimal::from('0')));
        $this->assertSame('0.700000000000000000', SimulationPosition::first()->quantity);
    }

    public function test_stop_exit_sells_the_held_position_records_realized_loss_and_starts_reentry_cooldown(): void
    {
        $policy = $this->policy();
        $this->market();
        $this->cycle($policy);
        $bought = SimulationPosition::firstOrFail()->quantity;
        $this->travel(5)->minutes();
        $this->quote('90.00', '90.02');
        $report = $this->cycle($policy);
        $this->assertSame(['signal', 'SELL', 'initial_stop_hit', 'order_created'], [$report['signals'][0]['status'], $report['signals'][0]['side'],
            $report['signals'][0]['reason'], $report['signals'][0]['handoff']['status']]);
        $sell = SimulationOrder::where('side', 'SELL')->firstOrFail();
        $this->assertSame(['filled', $bought, '89.910000000000000000'], [$sell->status, $sell->quantity, $sell->price_bound]);
        $position = SimulationPosition::firstOrFail();
        $this->assertSame(0, Decimal::from($position->quantity)->compareTo(Decimal::from('0')));
        $this->assertSame(-1, Decimal::from($position->realized_pnl)->compareTo(Decimal::from('0')));
        $this->travel(5)->minutes();
        $this->quote('99.69', '99.70');
        $this->cycle($policy);
        $this->assertSame(['wait', 'reentry_cooldown'], [StrategySignal::latest('id')->first()->status, StrategySignal::latest('id')->first()->reason]);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 2);
    }

    public function test_open_position_limit_counts_holdings_and_stops_the_fourth_entry(): void
    {
        $products = ['BTC-USD', 'ETH-USD', 'SOL-USD', 'XRP-USD'];
        config(['crypto.coinbase_products' => $products]);
        $policy = $this->policy($products);
        $this->market($products);
        $report = collect($this->cycle($policy)['signals'])->keyBy('symbol');
        foreach (['BTC-USD', 'ETH-USD', 'SOL-USD'] as $product) {
            $this->assertSame('order_created', $report[$product]['handoff']['status']);
        }
        $this->assertSame(['no_order', 'maximum_open_positions'], [$report['XRP-USD']['handoff']['status'], $report['XRP-USD']['handoff']['reason']]);
        $this->travel(5)->minutes();
        $this->quote('99.69', '99.70', products: $products);
        $this->assertSame(['wait', 'maximum_open_positions'], $this->latestReason('XRP-USD', $policy));
    }

    private function latestReason(string $product, InstrumentPolicy $policy): array
    {
        $this->cycle($policy);
        $signal = StrategySignal::where('symbol', $product)->latest('id')->first();

        return [$signal->status, $signal->reason];
    }

    public function test_daily_loss_pause_latches_for_the_rest_of_the_utc_day(): void
    {
        $policy = $this->policy();
        $this->market();
        Portfolio::whereKey($policy->paper_portfolio_id)->update(['cash' => '379.99']);
        $report = $this->cycle($policy);
        $this->assertSame('daily_loss_pause', $report['entry_pause']);
        $this->assertSame(['wait', 'daily_loss_pause'], [StrategySignal::latest('id')->first()->status, StrategySignal::latest('id')->first()->reason]);
        Portfolio::whereKey($policy->paper_portfolio_id)->update(['cash' => '400']);
        $this->travel(5)->minutes();
        $this->quote('99.69', '99.70');
        $this->assertSame('daily_loss_pause', $this->cycle($policy)['entry_pause']);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        // The next UTC day starts from the ledger at the boundary; the latch does not carry over.
        $this->travelTo(CarbonImmutable::parse('2026-10-23T00:10:00Z'));
        $this->quote('99.69', '99.70');
        $this->assertNull($this->cycle($policy)['entry_pause']);
    }

    public function test_policy_change_cancels_an_open_order_and_releases_cash(): void
    {
        $policy = $this->policy();
        $this->market(size: '0.4');
        $this->cycle($policy);
        $next = $this->policy(base: 1);
        $this->travel(5)->minutes();
        $this->quote('99.69', '99.70', '0.3');
        $this->cycle($next);
        $this->assertSame('cancelled', SimulationOrder::firstOrFail()->status);
        $this->assertSame('policy_changed', SimulationEvent::where('event_key', 'closed')->firstOrFail()->reason);
        $this->assertSame(0, Decimal::from(Portfolio::first()->reserved_cash)->compareTo(Decimal::from('0')));
    }

    public function test_tier_review_supplies_fees_only_for_the_reviewed_tier_unexcluded_products_and_one_day(): void
    {
        $policy = $this->policy(review: $this->review());
        $this->market();
        $fees = app(CryptoFeeObservations::class);
        $payload = $policy->payload;
        $this->assertNull($fees->schedule($this->owner->id, $payload, 'BTC-USD', CarbonImmutable::now()));
        $this->observation(['pricing_tier' => 'Advanced 1']);
        $this->assertNull($fees->schedule($this->owner->id, $payload, 'BTC-USD', CarbonImmutable::now()));
        $this->observation(['status' => 'failed', 'pricing_tier' => null, 'maker_rate' => null, 'taker_rate' => null, 'retrieved_at' => null, 'response_sha256' => null]);
        $this->assertNull($fees->schedule($this->owner->id, $payload, 'BTC-USD', CarbonImmutable::now()));
        $observation = $this->observation();
        $schedule = $fees->schedule($this->owner->id, $payload, 'BTC-USD', CarbonImmutable::now());
        $this->assertSame(['venue_notional_rate_v1', '0.005', '0.009', 'product_all_in', 'excluded_by_owner_direction'],
            [$schedule['kind'], $schedule['maker_rate'], $schedule['taker_rate'], $schedule['cost_scope'], $schedule['tax_treatment']]);
        $this->assertNull($fees->schedule($this->owner->id, $payload, 'USDT-USD', CarbonImmutable::now()));
        $this->assertNull($fees->schedule($this->owner->id, $payload, 'BTC-USD', CarbonImmutable::now()->addDay()));
        $this->observation(['reasons' => ['product_applicability_requires_owner_review', 'cost_plus_commission_unknown_or_unsupported'], 'retrieved_at' => now()->addSecond(), 'recorded_at' => now()->addSecond()]);
        $this->assertNull($fees->schedule($this->owner->id, $payload, 'BTC-USD', CarbonImmutable::now()->addSeconds(2)));
        // The evaluation uses the derived schedule and sizes with the greater (taker) rate.
        $this->assertSame('order_created', $this->cycle($policy)['signals'][0]['handoff']['status']);
        $this->assertSame('coinbase_tier_observation_'.$observation->id, SimulationOrder::firstOrFail()->assumptions['cost_schedule']['version']);
    }

    public function test_tier_review_input_is_validated_and_pinned_in_the_policy(): void
    {
        foreach ([['cost_scope' => 'account_tier'], ['tax_treatment' => 'ignored'], ['excluded_products' => ['bad id']], ['mode' => 'live'],
            ['reviewed_at' => now()->addMinute()->toISOString()], ['reviewed_at' => '2026-10-22 11:00']] as $change) {
            try {
                $this->policy(review: array_replace($this->review(), $change));
                $this->fail('Invalid review accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $policy = $this->policy(review: $this->review());
        $this->assertSame(['DAI-USD', 'USDT-USD'], $policy->payload['fee_review']['excluded_products']);
        $this->assertSame(CryptoPolicies::EXECUTION, $policy->payload['execution']);
    }

    public function test_scheduled_fee_refresh_records_redacted_observations_and_skips_recent_ones(): void
    {
        $this->policy();
        $signer = Mockery::mock(CoinbaseJwtSigner::class);
        $signer->shouldReceive('buildToken')->andReturn('fake-signed-token');
        $this->app->instance(CoinbaseJwtSigner::class, $signer);
        $resolver = Mockery::mock(BrokerCredentialResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(new BrokerCredential(['broker' => 'coinbase', 'status' => 'active', 'secret_ref' => 'fake-key', 'api_key_ref' => 'fake-secret']));
        $this->app->instance(BrokerCredentialResolver::class, $resolver);
        config(['crypto.fee_refresh_enabled' => false]);
        $this->assertSame(['status' => 'disabled'], app(CryptoFeeObservations::class)->scheduled());
        config(['crypto.fee_refresh_enabled' => true]);
        $fail = false;
        Http::fake(['api.coinbase.com/*' => function () use (&$fail) {
            return $fail ? Http::response(['message' => 'PRIVATE_PROVIDER_BODY'], 500) : Http::response(['fee_tier' => ['pricing_tier' => 'Intro',
                'maker_fee_rate' => '0.005', 'taker_fee_rate' => '0.009'], 'has_cost_plus_commission' => false, 'total_balance' => 'PRIVATE_BALANCE']);
        }]);
        $this->artisan('mcp:crypto-fees', ['--scheduled' => true])->assertSuccessful();
        $first = CryptoFeeObservation::firstOrFail();
        $this->assertSame(['complete', 'Intro', 'scheduled'], [$first->status, $first->pricing_tier, $first->trigger]);
        $this->assertStringNotContainsString('PRIVATE_BALANCE', json_encode($first->toArray()));
        app(CryptoFeeObservations::class)->scheduled();
        $this->assertDatabaseCount('mcp_crypto_fee_observations', 1);
        $this->travel(13)->hours();
        $fail = true;
        app(CryptoFeeObservations::class)->scheduled();
        $failed = CryptoFeeObservation::latest('id')->firstOrFail();
        $this->assertSame(['failed', ['fee_tier_read_unavailable'], null], [$failed->status, $failed->reasons, $failed->maker_rate]);
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_BODY', json_encode($failed->toArray()));
        // A failed attempt retries on a later hourly slot, not immediately.
        app(CryptoFeeObservations::class)->scheduled();
        $this->assertDatabaseCount('mcp_crypto_fee_observations', 2);
        $this->assertDatabaseCount('broker_orders', 0);
    }

    public function test_held_product_removed_from_the_list_stays_collected_and_evaluated_for_exit(): void
    {
        $policy = $this->policy();
        $this->market();
        $this->cycle($policy);
        config(['crypto.coinbase_products' => ['ETH-USD']]);
        $this->venue->known = ['BTC-USD', 'ETH-USD'];
        $result = app(CryptoMarketCollector::class)->tick();
        $this->assertSame(['ETH-USD', 'BTC-USD'], json_decode(DB::table('mcp_crypto_market_reads')->find($result['read_id'])->requested_products));
        $this->assertSame(2, $this->venue->count('/product_book'));
        $this->travel(5)->minutes();
        $this->quote('99.69', '99.70');
        $this->cycle($policy);
        $cycle = StrategyCycle::latest('id')->firstOrFail();
        $this->assertSame(['ETH-USD'], $cycle->payload['products']);
        $this->assertContains('BTC-USD', $cycle->payload['symbols']);
        $this->assertNotSame('product_fee_schedule_unavailable_or_expired', StrategySignal::where('symbol', 'BTC-USD')->latest('id')->first()->reason);
    }

    public function test_paper_command_schedule_and_health_respect_the_default_off_switches(): void
    {
        $policy = $this->policy();
        $this->market();
        config(['crypto.paper_enabled' => false]);
        $this->artisan('mcp:crypto-paper', ['--once' => true])->expectsOutput('{"status":"disabled"}')->assertSuccessful();
        $this->assertSame('disabled', app(OperationsHealth::class)->evaluate()['crypto_paper']['status']);
        $this->assertDatabaseCount('mcp_strategy_cycles', 0);
        config(['crypto.paper_enabled' => true]);
        $this->assertSame('critical', app(OperationsHealth::class)->evaluate()['crypto_paper']['status']);
        $this->cycle($policy);
        $this->assertSame('ok', app(OperationsHealth::class)->evaluate()['crypto_paper']['status']);
        config(['crypto.fee_refresh_enabled' => true]);
        $this->assertSame('warning', app(OperationsHealth::class)->evaluate()['crypto_paper']['status']);
        $this->assertCount(0, Schedule::events());
        require base_path('routes/console.php');
        $commands = collect(Schedule::events())->map(fn ($event) => $event->command)->implode("\n");
        $this->assertStringContainsString('mcp:crypto-paper --once --scheduled', $commands);
        $this->assertStringContainsString('mcp:crypto-fees --scheduled', $commands);
        $this->assertStringNotContainsString('mcp:crypto-market', $commands);
        $this->assertFalse(config('broker.coinbase.writes_enabled'));
    }

    public function test_evidence_only_mode_records_signals_without_orders(): void
    {
        $policy = $this->policy();
        $this->market();
        config(['crypto.paper_enabled' => false]);
        $report = $this->cycle($policy);
        $this->assertSame('signal', $report['signals'][0]['status']);
        $this->assertArrayNotHasKey('handoff', $report['signals'][0]);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        $this->assertFalse(StrategyCycle::firstOrFail()->payload['paper_orders']);
    }
}
