<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\ExecutionVersion;
use App\Models\PaperTrading\SignalExecutionPolicy;
use App\Models\PaperTrading\SignalOrderHandoff;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\Monitor;
use App\Services\PaperTrading\RealDataPaperFills;
use App\Services\PaperTrading\SignalExecutionPolicies;
use App\Services\PaperTrading\SignalOrderPrice;
use App\Services\PaperTrading\SignalPaperHandoff;
use App\Services\PaperTrading\StrategySignals;
use App\Services\PaperTrading\SymbolProfiles;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\SignalExecutionFixture as F;
use Tests\TestCase;

class FractionalMarketPaperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00.123456Z'));
    }

    /** Owner-applied market-order execution version plus signal settings; returns the signal settings payload. */
    private function market($c, array $signal = [], array $execution = []): array
    {
        E::apply($c, ['order_type' => 'market', 'allow_fractional' => true] + $execution);
        $settings = array_replace(SignalExecutionPolicies::DEFAULTS, ['slippage_bps' => '5', 'allow_fractional' => true], $signal);
        app(SignalExecutionPolicies::class)->apply($c, $c->user_id, 1, (string) Str::uuid(), $settings);

        return $settings;
    }

    private function quote($c, string $fractional = 'tradable', string $bid = '101.50', string $ask = '101.60', string $size = '100')
    {
        return F::quote($c, $bid, $ask, $size, ['execution_reads.tradability.fields.fractional_tradability' => $fractional]);
    }

    private function buy($c): SignalOrderHandoff
    {
        $signal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];

        return app(SignalPaperHandoff::class)->process($c, $c->user_id, $signal->id);
    }

    public function test_guarded_fractional_market_buy_fills_immediately_at_the_ask_plus_slippage(): void
    {
        [$c, $p] = F::seed();
        $this->market($c);
        $this->quote($c);
        $network = count(Http::recorded());
        $cash = Decimal::from($p->fresh()->cash);
        $outcome = $this->buy($c);
        $this->assertSame('order_created', $outcome->status, json_encode($outcome->payload).' '.$outcome->reason);
        $this->assertSame(['type' => 'market', 'quantity_scale' => 6, 'fractional_tradable' => true], $outcome->payload['order']);
        $order = SimulationOrder::findOrFail($outcome->sim_order_id);
        // Filled on acceptance against the guarded quote: no later observation was needed.
        $this->assertSame(['market', 'filled'], [$order->type, $order->status]);
        $quantity = Decimal::from($order->quantity);
        $this->assertNotSame(0, $quantity->compareTo($quantity->rounded(0, RoundingMode::DOWN)), 'Expected a fractional quantity');
        $this->assertSame(0, $quantity->compareTo($quantity->rounded(6, RoundingMode::DOWN)));
        $this->assertSame(0, $quantity->compareTo(Decimal::from($outcome->payload['sizing']['quantity'])));
        $fill = SimulationEvent::where('sim_order_id', $order->id)->where('kind', 'fill')->sole();
        $this->assertSame('101.650800000000000000', $fill->price); // 101.60 plus 5 bp
        $this->assertSame(0, $cash->minus($quantity->multipliedBy(Decimal::from($fill->price)))->minus(Decimal::from($fill->fee))->compareTo(Decimal::from($p->fresh()->cash)));
        $this->assertSame('0.000000000000000000', $p->fresh()->reserved_cash);
        $this->assertSame(0, $quantity->compareTo(Decimal::from(SimulationPosition::sole()->quantity)));
        // The price bound is the highest acceptable fill: the profile's maximum entry plus slippage.
        $this->assertSame(0, Decimal::from($order->price_bound)->compareTo(Decimal::from($outcome->payload['sizing']['price_bound'])));
        // Repeating the handoff or a later fill pass changes nothing.
        $this->assertSame($outcome->id, app(SignalPaperHandoff::class)->process($c, $c->user_id, $outcome->signal_id)->id);
        $this->assertSame('filled', app(RealDataPaperFills::class)->process($c, $c->user_id, $order->id)->status);
        $this->assertSame(1, SimulationEvent::where('kind', 'fill')->count());
        $this->assertCount($network, Http::recorded());
    }

    public function test_small_position_limits_buy_fractions_where_whole_shares_size_to_zero(): void
    {
        [$c] = F::seed();
        // A 0.5% position limit on $10,000 is $50: less than one $101.60 share, like a $500 account at 10%.
        $this->market($c, ['max_position_pct' => '0.5']);
        $this->quote($c);
        $outcome = $this->buy($c);
        $order = SimulationOrder::findOrFail($outcome->sim_order_id);
        $this->assertSame('filled', $order->status);
        $this->assertSame(-1, Decimal::from($order->quantity)->compareTo(Decimal::from('1')));
    }

    public function test_without_robinhood_fractional_eligibility_the_same_limit_sizes_to_zero_whole_shares(): void
    {
        [$c] = F::seed();
        $this->market($c, ['max_position_pct' => '0.5']);
        $this->quote($c, 'untradable');
        $skipped = $this->buy($c);
        $this->assertSame(['no_order', 'whole_share_size_zero'], [$skipped->status, $skipped->reason]);
        $this->assertSame(['type' => 'market', 'quantity_scale' => 0, 'fractional_tradable' => false], $skipped->payload['order']);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
    }

    public function test_missing_fractional_eligibility_uses_whole_share_market_orders(): void
    {
        [$c] = F::seed();
        $this->market($c);
        F::quote($c); // the tradability read carries no fractional_tradability value
        $outcome = $this->buy($c);
        $this->assertSame(['type' => 'market', 'quantity_scale' => 0, 'fractional_tradable' => null], $outcome->payload['order']);
        $order = SimulationOrder::findOrFail($outcome->sim_order_id);
        $this->assertSame(['filled', '19.000000000000000000'], [$order->status, $order->quantity]);
    }

    public function test_a_quote_that_moved_past_the_maximum_entry_rejects_the_market_buy(): void
    {
        $this->assertSame('entry_trigger_no_longer_valid', $this->rejectedAfter(fn () => ['102.30', '102.40']));
    }

    public function test_an_ask_above_the_stored_maximum_entry_fails_the_price_guard_even_within_the_chase_rule(): void
    {
        $this->assertSame('market_price_guard_failed', $this->rejectedAfter(function ($c) {
            // Test-only: lower this profile's stored maximum entry to 0.25 ATR above the trigger (real profiles round it down
            // to 4 decimals, leaving a narrower band). The entry rule still allows asks up to 0.5 ATR above the trigger.
            $profile = app(SymbolProfiles::class)->latest($c, 'DEMO', CarbonImmutable::now());
            $payload = $profile->payload;
            $trigger = Decimal::from($payload['levels']['trigger']);
            $atr = Decimal::from($payload['indicators']['atr']);
            $payload['levels']['max_entry'] = (string) $trigger->plus($atr->multipliedBy(Decimal::from('0.25')));
            DB::table('mcp_symbol_profiles')->where('id', $profile->id)->update(['payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))]);
            $ask = $trigger->plus($atr->multipliedBy(Decimal::from('0.4')))->rounded(2, RoundingMode::DOWN);

            return [(string) $ask->minus(Decimal::from('0.10')), (string) $ask];
        }));
    }

    public function test_a_spread_wider_than_the_limit_rejects_the_market_buy(): void
    {
        $this->assertSame('rule_eligibility_ineligible', $this->rejectedAfter(fn () => ['100.80', '101.60']));
    }

    /** A newer observation arrives between the signal and its handoff; nothing fills and no cash is reserved. */
    private function rejectedAfter(callable $prices): string
    {
        [$c, $p] = F::seed();
        $this->market($c);
        $this->quote($c);
        $signal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        $this->travel(1)->seconds();
        [$bid, $ask] = $prices($c);
        $this->quote($c, 'tradable', $bid, $ask);
        $outcome = app(SignalPaperHandoff::class)->process($c, $c->user_id, $signal->id);
        $this->assertSame('rejected', $outcome->status, (string) $outcome->reason);
        $this->assertSame(0, SimulationEvent::where('kind', 'fill')->count());
        $this->assertSame('0.000000000000000000', $p->fresh()->reserved_cash);
        $this->assertSame('10000.000000000000000000', $p->fresh()->cash);

        return SimulationEvent::where('sim_order_id', $outcome->sim_order_id)->sole()->reason;
    }

    public function test_fractional_exits_are_market_sells_without_a_price_floor(): void
    {
        [$c, $p] = F::seed();
        $this->market($c);
        $this->quote($c);
        $held = Decimal::from(SimulationOrder::findOrFail($this->buy($c)->sim_order_id)->quantity);
        $this->travelTo(CarbonImmutable::parse('2026-10-20T14:00:00.123456Z'));
        app(Monitor::class)->sync($c->id, 'sell:'.Str::uuid());
        $this->quote($c, 'tradable', '97', '97.1');
        $signal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        $this->assertSame('SELL', $signal->side);
        $sell = app(SignalPaperHandoff::class)->process($c, $c->user_id, $signal->id);
        $this->assertSame('order_created', $sell->status, $sell->reason.' '.json_encode($sell->payload));
        $order = SimulationOrder::findOrFail($sell->sim_order_id);
        $this->assertSame(['market', 'filled', SignalOrderPrice::MARKET_SELL_FLOOR], [$order->type, $order->status, rtrim(rtrim($order->price_bound, '0'), '.')]);
        $this->assertSame(0, $held->compareTo(Decimal::from($order->quantity)));
        $this->assertSame('96.951500000000000000', SimulationEvent::where('sim_order_id', $order->id)->where('kind', 'fill')->sole()->price); // 97 less 5 bp
        $this->assertSame('0.000000000000000000', SimulationPosition::sole()->quantity);
        $this->assertSame('0.000000000000000000', $p->fresh()->reserved_cash);
    }

    public function test_a_symbol_that_loses_fractional_eligibility_sells_its_whole_shares_and_records_the_fraction(): void
    {
        [$c] = F::seed();
        $this->market($c);
        $this->quote($c);
        $held = Decimal::from(SimulationOrder::findOrFail($this->buy($c)->sim_order_id)->quantity);
        $this->travelTo(CarbonImmutable::parse('2026-10-20T14:00:00.123456Z'));
        app(Monitor::class)->sync($c->id, 'sell:'.Str::uuid());
        $this->quote($c, 'untradable', '97', '97.1');
        $signal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        $sell = app(SignalPaperHandoff::class)->process($c, $c->user_id, $signal->id);
        $whole = $held->rounded(0, RoundingMode::DOWN);
        $this->assertSame(0, $whole->compareTo(Decimal::from(SimulationOrder::findOrFail($sell->sim_order_id)->quantity)));
        $this->assertSame(0, $held->minus($whole)->compareTo(Decimal::from($sell->payload['order']['unsold_fraction'])));
        $this->assertSame(0, $held->minus($whole)->compareTo(Decimal::from(SimulationPosition::sole()->quantity)));
    }

    public function test_settings_allow_fractional_shares_only_on_market_orders_with_conservative_slippage(): void
    {
        [$c] = F::seed();
        foreach (['limit execution version' => [[], []], 'market without fractional permission' => [['allow_fractional' => false], []],
            'slippage below the market floor' => [[], ['slippage_bps' => '4.99']]] as $case => [$execution, $signal]) {
            if ($execution !== [] || $case !== 'limit execution version') {
                E::apply($c, array_replace(['order_type' => 'market', 'allow_fractional' => true], $execution));
            }
            try {
                app(SignalExecutionPolicies::class)->apply($c, $c->user_id, 1, (string) Str::uuid(),
                    array_replace(SignalExecutionPolicies::DEFAULTS, ['slippage_bps' => '5', 'allow_fractional' => true], $signal));
                $this->fail('Accepted: '.$case);
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode(), $case);
            }
        }
        $this->assertSame(1, SignalExecutionPolicy::count());
        // From the Control Center form the refusal is shown on the page, not as a bare error.
        $form = ['account_scope' => AccountScope::key($c), 'base_version' => 1, 'request_id' => (string) Str::uuid(), 'confirm_signal_paper' => '1',
            'execution_version_id' => ExecutionVersion::latest('id')->first()->id, 'signal_execution' => ['slippage_bps' => '1', 'allow_fractional' => '1'] + SignalExecutionPolicies::DEFAULTS];
        $this->actingAs(User::findOrFail($c->user_id))->from('/mcp/dashboard/control')->post('/mcp/paper/signal-execution/apply', $form)
            ->assertRedirect('/mcp/dashboard/control')->assertSessionHasErrors('signal_execution');
        $this->followRedirects($this->from('/mcp/dashboard/control')->post('/mcp/paper/signal-execution/apply', ['request_id' => (string) Str::uuid()] + $form))
            ->assertOk()->assertSee('Change not recorded')->assertSee('basis points of slippage');
        // The owner form sends '1'; the applied version records a boolean.
        E::apply($c, ['order_type' => 'market', 'allow_fractional' => true]);
        $this->actingAs(User::findOrFail($c->user_id))->post('/mcp/paper/signal-execution/apply', ['account_scope' => AccountScope::key($c), 'base_version' => 1,
            'request_id' => (string) Str::uuid(), 'execution_version_id' => ExecutionVersion::latest('id')->first()->id, 'confirm_signal_paper' => '1',
            'signal_execution' => ['slippage_bps' => '5', 'allow_fractional' => '1'] + SignalExecutionPolicies::DEFAULTS])->assertRedirect();
        $this->assertTrue(SignalExecutionPolicy::latest('version')->first()->payload['allow_fractional']);
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Fractional on market orders')->assertSee('Market orders with a submission price guard');
    }
}
