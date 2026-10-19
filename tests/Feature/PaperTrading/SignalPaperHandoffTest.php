<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\SignalOrderHandoff;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Monitoring\Monitor;
use App\Services\PaperTrading\RealDataPaperFills;
use App\Services\PaperTrading\SignalExecutionPolicies;
use App\Services\PaperTrading\SignalPaperCycle;
use App\Services\PaperTrading\SignalPaperHandoff;
use App\Services\PaperTrading\StrategySignals;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ExternalOrderGateway;
use Tests\Support\SignalExecutionFixture as F;
use Tests\TestCase;

class SignalPaperHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00.123456Z'));
    }

    public function test_recorded_signal_reaches_risk_sized_paper_order_and_only_a_later_observation_can_fill_it(): void
    {
        [$c, $p] = F::seed();
        $network = count(Http::recorded());
        $signal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        $outcome = app(SignalPaperHandoff::class)->process($c, $c->user_id, $signal->id);
        $this->assertSame('order_created', $outcome->status, json_encode($outcome->payload).' reason='.$outcome->reason);
        $order = SimulationOrder::findOrFail($outcome->sim_order_id);
        $this->assertSame('19.000000000000000000', $order->quantity);
        $this->assertSame($signal->id, $order->intent['strategy_signal_id']);
        $this->assertSame('open', app(RealDataPaperFills::class)->process($c, $c->user_id, $order->id)->status);
        $this->assertSame($outcome->id, app(SignalPaperHandoff::class)->process($c, $c->user_id, $signal->id)->id);
        $this->travel(1)->seconds();
        F::quote($c);
        $filled = app(RealDataPaperFills::class)->process($c, $c->user_id, $order->id);
        $this->assertSame('filled', $filled->status, SimulationEvent::where('sim_order_id', $order->id)->get()->pluck('reason')->toJson());
        $this->assertSame('0.000000000000000000', SimulationEvent::where('sim_order_id', $order->id)->where('kind', 'fill')->sole()->fee);
        $this->assertSame('0.000000000000000000', $p->fresh()->reserved_cash);
        $this->assertSame('19.000000000000000000', SimulationPosition::sole()->quantity);
        $this->assertCount($network, Http::recorded());
    }

    private function buy($c)
    {
        $signal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];

        return app(SignalPaperHandoff::class)->process($c, $c->user_id, $signal->id);
    }

    public function test_next_day_exit_needs_no_opening_baseline_and_uses_sell_only_regulatory_fees(): void
    {
        [$c] = F::seed();
        $buy = $this->buy($c);
        $this->travel(1)->seconds();
        F::quote($c);
        $this->assertSame('filled', app(RealDataPaperFills::class)->process($c, $c->user_id, $buy->sim_order_id)->status);
        $this->travelTo(CarbonImmutable::parse('2026-10-20T14:00:00.123456Z'));
        app(Monitor::class)->sync($c->id, 'sell:'.Str::uuid());
        F::quote($c, '97', '97.1');
        $sellSignal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        $this->assertSame('SELL', $sellSignal->side);
        $sell = app(SignalPaperHandoff::class)->process($c, $c->user_id, $sellSignal->id);
        $this->assertSame('order_created', $sell->status, json_encode($sell->payload).' '.$sell->reason);
        $this->travel(1)->seconds();
        F::quote($c, '97', '97.1');
        $this->assertSame('filled', app(RealDataPaperFills::class)->process($c, $c->user_id, $sell->sim_order_id)->status);
        $this->assertSame('0.040000000000000000', SimulationEvent::where('sim_order_id', $sell->sim_order_id)->where('kind', 'fill')->sole()->fee);
        $this->assertSame('0.000000000000000000', SimulationPosition::sole()->quantity);
    }

    public function test_partial_fills_share_reservations_and_retries_cannot_duplicate_a_fill(): void
    {
        [$c, $p] = F::seed();
        $buy = $this->buy($c);
        $this->travel(1)->seconds();
        F::quote($c, size: '3');
        $filled = app(RealDataPaperFills::class)->process($c, $c->user_id, $buy->sim_order_id);
        $this->assertSame('partial', $filled->status);
        $this->assertSame('3.000000000000000000', $filled->filled_quantity);
        app(RealDataPaperFills::class)->process($c, $c->user_id, $buy->sim_order_id);
        $this->assertSame(1, SimulationEvent::where('sim_order_id', $buy->sim_order_id)->where('kind', 'fill')->count());
        $this->travel(1)->seconds();
        F::quote($c);
        $this->assertSame('filled', app(RealDataPaperFills::class)->process($c, $c->user_id, $buy->sim_order_id)->status);
        $this->assertSame('0.000000000000000000', $p->fresh()->reserved_cash);
    }

    public function test_replaced_signal_policy_cancels_a_pending_buy_without_requiring_a_quote(): void
    {
        [$c, $p] = F::seed();
        $buy = $this->buy($c);
        app(SignalExecutionPolicies::class)->apply($c, $c->user_id, 1, (string) Str::uuid(),
            ['risk_per_trade_pct' => '0.5'] + SignalExecutionPolicies::DEFAULTS);
        $this->travel(10)->minutes();
        $this->assertSame('cancelled', app(RealDataPaperFills::class)->process($c, $c->user_id, $buy->sim_order_id)->status);
        $this->assertSame('0.000000000000000000', $p->fresh()->reserved_cash);
    }

    public function test_stale_signal_and_unknown_data_are_audited_without_reserving_cash(): void
    {
        [$c, $p] = F::seed();
        $signal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        $this->travel(5)->minutes();
        $outcome = app(SignalPaperHandoff::class)->process($c, $c->user_id, $signal->id);
        $this->assertSame('signal_stale_or_settings_changed', $outcome->reason);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        $this->assertSame('0.000000000000000000', $p->fresh()->reserved_cash);
    }

    public function test_handoff_failure_after_intent_creation_rolls_back_and_recovers_once(): void
    {
        [$c, $p] = F::seed();
        $crash = true;
        SignalOrderHandoff::creating(function () use (&$crash) {
            if ($crash) {
                throw new \RuntimeException('synthetic handoff crash');
            }
        });
        try {
            $this->buy($c);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic handoff crash', $e->getMessage());
        } finally {
            $crash = false;
        }
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        $this->assertSame('0.000000000000000000', $p->fresh()->reserved_cash);
        $this->assertSame('order_created', $this->buy($c)->status);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 1);
    }

    public function test_paper_cycle_fills_existing_orders_before_new_handoffs_and_is_safe_to_repeat(): void
    {
        [$c] = F::seed();
        $cycle = app(SignalPaperCycle::class);
        $this->assertGreaterThan(0, $cycle->tick($c->id));
        $order = SimulationOrder::sole();
        $cycle->tick($c->id);
        $this->assertSame('open', $order->fresh()->status);
        $this->travel(1)->seconds();
        F::quote($c);
        $cycle->tick($c->id);
        $this->assertSame('filled', $order->fresh()->status);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 1);
        $this->assertSame(1, SimulationEvent::where('kind', 'fill')->count());
    }

    public function test_unmarketable_exit_is_cancelled_and_repriced_without_doubling_reserved_shares(): void
    {
        [$c] = F::seed();
        $buy = $this->buy($c);
        $this->travel(1)->seconds();
        F::quote($c);
        app(RealDataPaperFills::class)->process($c, $c->user_id, $buy->sim_order_id);
        $this->travel(5)->minutes();
        F::quote($c, '97', '97.1');
        $signal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        $first = app(SignalPaperHandoff::class)->process($c, $c->user_id, $signal->id);
        $this->assertSame('order_created', $first->status);
        $this->travel(5)->minutes();
        F::quote($c, '96', '96.1');
        app(SignalPaperCycle::class)->tick($c->id);
        $this->assertSame('cancelled', SimulationOrder::findOrFail($first->sim_order_id)->status);
        $replacement = SimulationOrder::where('side', 'SELL')->where('status', 'open')->sole();
        $this->assertNotSame($replacement->id, $first->sim_order_id);
        $this->assertSame('19.000000000000000000', SimulationPosition::sole()->reserved_quantity);
    }

    public function test_an_external_order_discovered_after_a_buy_signal_stops_its_handoff(): void
    {
        [$c, $p] = F::seed();
        $signal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        $gateway = new ExternalOrderGateway;
        $gateway->orders = [['id' => 'synthetic-outside-order']];
        $this->app->instance(BrokerGateway::class, $gateway);
        app(Monitor::class)->sync($c->id, 'outside:'.Str::uuid());
        $result = app(SignalPaperHandoff::class)->process($c, $c->user_id, $signal->id);
        $this->assertSame('rejected', $result->status);
        $this->assertSame('0.000000000000000000', $p->fresh()->reserved_cash);
        $this->assertDatabaseCount('mcp_paper_sim_positions', 0);
    }

    public function test_dated_cost_settings_expire_instead_of_guessing_future_rates(): void
    {
        [$c, $p] = F::seed();
        $buy = $this->buy($c);
        $this->travelTo(CarbonImmutable::parse('2027-01-01T15:00:00Z'));
        $order = app(RealDataPaperFills::class)->process($c, $c->user_id, $buy->sim_order_id);
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('cost_schedule_expired_or_unreviewed', SimulationEvent::where('sim_order_id', $order->id)->latest('id')->first()->reason);
        $this->assertSame('0.000000000000000000', $p->fresh()->reserved_cash);
    }
}
