<?php

namespace Tests\Feature\PaperTrading;

use App\Models\BrokerOrder;
use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationObservation;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\User;
use App\Services\Monitoring\MarketCalendar;
use App\Services\PaperTrading\OfflineSimulator;
use App\Services\PaperTrading\PaperMandates;
use App\Services\PaperTrading\PaperPolicies;
use App\Services\PaperTrading\SimulationOverview;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\McpFixture;
use Tests\Support\SimulationFixture as F;
use Tests\TestCase;

class OfflineSimulatorTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private $portfolio;

    private $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection, $this->portfolio, $this->policy] = F::seed();
        Queue::fake();
    }

    private function submit(array $intent = [], array $assumptions = [], ?array $marks = null): SimulationOrder
    {
        return app(OfflineSimulator::class)->submit($this->connection, $this->connection->user_id, $this->policy->id,
            $this->portfolio->fresh()->ledger_version, F::intent($intent), F::assumptions($assumptions), $marks ?? ['DEMO' => F::quote()]);
    }

    private function observe(SimulationOrder $order, array $quote = [], array $marks = []): SimulationOrder
    {
        return app(OfflineSimulator::class)->observe($this->connection, $this->connection->user_id, $order->id, F::quote($quote), $marks);
    }

    private function decimal(string $expected, string $actual): void
    {
        $this->assertSame(0, Decimal::from($expected)->compareTo(Decimal::from($actual)), "$expected != $actual");
    }

    public function test_intent_reserves_without_filling_and_retry_cannot_change_or_duplicate_it(): void
    {
        $intent = F::intent();
        $args = [$this->connection, $this->connection->user_id, $this->policy->id, 1, $intent, F::assumptions(), ['DEMO' => F::quote()]];
        $order = app(OfflineSimulator::class)->submit(...$args);
        $this->assertSame('open', $order->status);
        $this->decimal('1010.1', $this->portfolio->fresh()->reserved_cash);
        $this->decimal('10000', $this->portfolio->fresh()->cash);
        $this->assertSame($order->id, app(OfflineSimulator::class)->submit(...$args)->id);
        $this->assertSame(1, SimulationOrder::count());
        $this->assertSame(1, CashEntry::count());
        $this->assertSame(0, SimulationPosition::count());
        $this->assertStringNotContainsString('Synthetic proposal', DB::table('mcp_paper_sim_orders')->sole()->intent);
        $args[4]['quantity'] = '11';
        try {
            app(OfflineSimulator::class)->submit(...$args);
            $this->fail('Changed intent reused a key.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertFalse(config('research.enabled'));
        $this->assertFalse(config('paper.decisions_enabled'));
        $this->assertFalse(config('mcp.brokerage_writes_enabled'));
        $this->assertSame(0, BrokerOrder::count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_partial_fill_restart_duplicate_and_round_trip_preserve_cash_quantity_and_thesis(): void
    {
        $buy = $this->submit();
        $this->travel(1)->seconds();
        $quote = F::quote(['ask_size' => '4']);
        $simulator = app(OfflineSimulator::class);
        $buy = $simulator->observe($this->connection, $this->connection->user_id, $buy->id, $quote);
        $this->assertSame('partial', $buy->status);
        $this->decimal('4', $buy->filled_quantity);
        $this->decimal('9599.56', $this->portfolio->fresh()->cash);
        $this->decimal('606.06', $this->portfolio->fresh()->reserved_cash);
        // A newly constructed service has no process-local idempotency state.
        (new OfflineSimulator(app(MarketCalendar::class)))->observe($this->connection, $this->connection->user_id, $buy->id, $quote);
        $this->assertSame(1, SimulationEvent::where('kind', 'fill')->count());
        $this->travel(1)->seconds();
        $buy = $this->observe($buy, ['ask_size' => '6']);
        $this->assertSame('filled', $buy->status);
        $this->decimal('8998.9', $this->portfolio->fresh()->cash);
        $this->decimal('0', $this->portfolio->fresh()->reserved_cash);
        $this->decimal('1001.1', SimulationPosition::sole()->cost_basis);
        $sell = $this->submit(['side' => 'SELL', 'price_bound' => '105'], [], ['DEMO' => F::quote(['bid' => '110', 'ask' => '111'])]);
        $this->decimal('10', SimulationPosition::sole()->reserved_quantity);
        $this->travel(1)->seconds();
        $sell = $this->observe($sell, ['bid' => '110', 'ask' => '111']);
        $this->assertSame('filled', $sell->status);
        $position = SimulationPosition::sole();
        $this->decimal('0', $position->quantity);
        $this->decimal('0', $position->reserved_quantity);
        $this->decimal('0', $position->cost_basis);
        $this->decimal('97.7', $position->realized_pnl);
        $this->decimal('10097.7', $this->portfolio->fresh()->cash);
        $cash = Decimal::from('0');
        foreach (CashEntry::all() as $entry) {
            $cash = $cash->plus(Decimal::from($entry->amount));
        }
        $this->decimal((string) $cash, $this->portfolio->fresh()->cash);
        $this->assertSame($buy->intent['rationale'], $sell->intent['rationale']);
        $this->assertSame(0, BrokerOrder::count());
        Http::assertNothingSent();
    }

    public function test_same_quote_as_decision_stale_future_and_unknown_inputs_cannot_fill(): void
    {
        $order = $this->submit();
        $this->observe($order);
        $this->assertSame('observation_not_after_order', SimulationEvent::latest('id')->first()->reason);
        $this->travel(2)->seconds();
        foreach ([['source_at' => null], ['source_at' => now()->subMinutes(2)->toIso8601String()],
            ['source_at' => now()->addMinutes(2)->toIso8601String()], ['retrieved_at' => now()->addMinutes(2)->toIso8601String()],
            ['ask' => null], ['ask_size' => null], ['eligible' => null], ['corporate_actions_clear' => false], ['bid' => '101', 'ask' => '100']] as $changes) {
            // Distinct source times identify distinct synthetic samples, never re-polled copies of one tick.
            $this->travel(1)->seconds();
            $this->observe($order, $changes);
        }
        $this->assertSame(0, SimulationEvent::where('kind', 'fill')->count());
        $this->decimal('10000', $this->portfolio->fresh()->cash);
        $this->assertSame('open', $order->fresh()->status);
    }

    public function test_limits_remain_unfilled_and_expiry_and_cancel_release_only_remaining_reservations(): void
    {
        $order = $this->submit();
        $this->travel(1)->seconds();
        $this->observe($order, ['bid' => '102', 'ask' => '103']);
        $this->assertSame('limit_not_marketable', SimulationEvent::latest('id')->first()->reason);
        $this->travel(1)->seconds();
        $this->observe($order, ['ask_size' => '3']);
        config(['paper.offline_simulation_enabled' => false]);
        $simulator = app(OfflineSimulator::class);
        $simulator->cancel($this->connection, $this->connection->user_id, $order->id);
        $simulator->cancel($this->connection, $this->connection->user_id, $order->id);
        $this->decimal('0', $this->portfolio->fresh()->reserved_cash);
        $this->decimal('3', SimulationPosition::sole()->quantity);
        $this->assertSame(1, SimulationEvent::where('kind', 'cancelled')->count());
        config(['paper.offline_simulation_enabled' => true]);
        $expiring = $this->submit(['expires_at' => now()->addSecond()->toIso8601String()]);
        $this->travel(1)->seconds();
        $this->assertSame(1, $simulator->expire($this->connection, $this->connection->user_id));
        $this->assertSame(0, $simulator->expire($this->connection, $this->connection->user_id));
        $this->assertSame('expired', $expiring->fresh()->status);
        $this->decimal('0', $this->portfolio->fresh()->reserved_cash);
    }

    public function test_shared_liquidity_cannot_be_reused_by_another_order_or_renamed_tick(): void
    {
        $first = $this->submit(['quantity' => '5']);
        $second = $this->submit(['quantity' => '5']);
        $this->travel(1)->seconds();
        $quote = F::quote(['ask_size' => '6']);
        $sim = app(OfflineSimulator::class);
        $sim->observe($this->connection, $this->connection->user_id, $first->id, $quote);
        $sim->observe($this->connection, $this->connection->user_id, $second->id, $quote);
        $this->decimal('5', $first->fresh()->filled_quantity);
        $this->decimal('1', $second->fresh()->filled_quantity);
        $this->assertSame(1, SimulationObservation::count());
        $this->expectException(HttpException::class);
        $this->observe($second, array_replace($quote->data, ['key' => 'renamed-tick']));
    }

    public function test_cash_position_allocation_and_synthetic_loss_limits_include_pending_orders(): void
    {
        $first = $this->submit(['quantity' => '15']);
        $second = $this->submit(['quantity' => '10']);
        $this->assertSame('open', $first->status);
        $this->assertSame('rejected', $second->status);
        $this->assertSame('position_limit_exceeded', SimulationEvent::latest('id')->first()->reason);
        $this->decimal('1515.15', $this->portfolio->fresh()->reserved_cash);
        $noCash = $this->submit(['quantity' => '1000']);
        $this->assertSame('rejected', $noCash->status);
        $this->assertSame('insufficient_unreserved_cash', SimulationEvent::latest('id')->first()->reason);
        $short = $this->submit(['side' => 'SELL']);
        $this->assertSame('rejected', $short->status);
        $this->assertSame('insufficient_unreserved_shares', SimulationEvent::latest('id')->first()->reason);
        app(OfflineSimulator::class)->cancel($this->connection, $this->connection->user_id, $first->id);
        $loss = $this->submit([], ['session_open_equity' => '11000']);
        $this->assertSame('rejected', $loss->status);
        $this->assertSame('session_baseline_changed', SimulationEvent::latest('id')->first()->reason);
    }

    public function test_fractional_support_is_explicit_and_whole_share_orders_do_not_fill_fractionally(): void
    {
        $order = $this->submit(['quantity' => '0.1']);
        $this->assertSame('rejected', $order->status);
        $this->assertSame('fractional_order_unsupported', SimulationEvent::latest('id')->first()->reason);
        $order = $this->submit(['quantity' => '0.1'], ['allow_fractional' => true]);
        $this->assertSame('rejected', $order->status);
        $order = $this->submit(['quantity' => '0.10000001'], ['allow_fractional' => true, 'allow_fractional_limit' => true]);
        $this->travel(1)->seconds();
        $this->observe($order, ['ask_size' => '0.10000001']);
        $this->assertSame('filled', $order->fresh()->status);
        $this->decimal('0.10000001', SimulationPosition::sole()->quantity);
        $whole = $this->submit(['quantity' => '2'], ['allow_fractional' => true, 'allow_fractional_limit' => true]);
        $this->travel(1)->seconds();
        $this->observe($whole, ['ask_size' => '0.5', 'fractional' => false]);
        $this->assertSame('open', $whole->fresh()->status);
        $this->assertSame('liquidity_exhausted', SimulationEvent::latest('id')->first()->reason);
    }

    public function test_early_close_unknown_sessions_and_expired_market_orders_do_not_fill(): void
    {
        $order = $this->submit(['type' => 'market']);
        $this->travel(1)->seconds();
        $this->observe($order, ['bid' => '103', 'ask' => '104']);
        $this->assertSame('market_price_bound', SimulationEvent::latest('id')->first()->reason);
        app(MarketCalendar::class)->store('2026-10-19', 'early_close', '2026-10-19T13:30:00Z', now()->toIso8601String(), 'synthetic_early_close');
        $this->travel(1)->seconds();
        $this->observe($order);
        $this->assertSame('outside_verified_session', SimulationEvent::latest('id')->first()->reason);
        $this->travel(11)->minutes();
        $this->observe($order);
        $this->assertSame('expired', $order->fresh()->status);
        $this->assertSame(0, SimulationEvent::where('kind', 'fill')->count());
        $this->assertSame('rejected', $this->submit()->status);
    }

    public function test_changed_authorization_policy_and_foreign_actor_cannot_create_fills(): void
    {
        $order = $this->submit();
        $this->connection->increment('auth_generation');
        $this->travel(1)->seconds();
        $this->observe($order);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('authorization_changed', SimulationEvent::latest('id')->first()->reason);
        $this->decimal('0', $this->portfolio->fresh()->reserved_cash);
        $order = $this->submit();
        $this->portfolio->increment('policy_revision');
        $this->observe($order);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('policy_changed', SimulationEvent::latest('id')->first()->reason);
        $this->expectException(HttpException::class);
        app(OfflineSimulator::class)->cancel($this->connection, $this->connection->user_id + 100, $order->id);
    }

    public function test_failure_after_fill_writes_rolls_back_every_mutation_and_can_retry_once(): void
    {
        $order = $this->submit();
        $this->travel(1)->seconds();
        $quote = F::quote();
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into "mcp_paper_cash_entries"')) {
                throw new \RuntimeException('Synthetic crash before commit.');
            }
        });
        try {
            app(OfflineSimulator::class)->observe($this->connection, $this->connection->user_id, $order->id, $quote);
            $this->fail('Crash was not observed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic crash before commit.', $e->getMessage());
        }
        $this->decimal('10000', $this->portfolio->fresh()->cash);
        $this->assertSame('open', $order->fresh()->status);
        $this->assertSame(0, SimulationPosition::count());
        $this->assertSame(0, SimulationObservation::count());
        $this->assertSame(0, SimulationEvent::where('kind', 'fill')->count());
        $fail = false;
        app(OfflineSimulator::class)->observe($this->connection, $this->connection->user_id, $order->id, $quote);
        $this->assertSame('filled', $order->fresh()->status);
        $this->assertSame(1, SimulationEvent::where('kind', 'fill')->count());
    }

    public function test_disabled_gate_is_independent_of_model_credits_and_credentials(): void
    {
        config(['paper.offline_simulation_enabled' => false, 'research.enabled' => true, 'research.api_key' => 'synthetic']);
        $this->expectException(HttpException::class);
        $this->submit();
    }

    public function test_losing_exit_preserves_evidence_and_basis_conservation_after_partial_sales(): void
    {
        $buy = $this->submit();
        $this->travel(1)->seconds();
        $this->observe($buy);
        $sell = $this->submit(['side' => 'SELL', 'price_bound' => '80']);
        $this->travel(1)->seconds();
        $this->observe($sell, ['bid' => '90', 'ask' => '91', 'bid_size' => '3']);
        $this->decimal('7', SimulationPosition::sole()->quantity);
        $this->decimal('700.77', SimulationPosition::sole()->cost_basis);
        $this->decimal('-30.63', SimulationPosition::sole()->realized_pnl);
        $this->travel(1)->seconds();
        $this->observe($sell, ['bid' => '90', 'ask' => '91', 'bid_size' => '7']);
        $this->decimal('-102.1', SimulationPosition::sole()->realized_pnl);
        $this->decimal('9897.9', $this->portfolio->fresh()->cash);
        $this->decimal('0', SimulationPosition::sole()->cost_basis);
        $this->assertStringContainsString('Synthetic proposal retained', $buy->fresh()->intent['rationale']);
        $this->assertStringContainsString('Synthetic proposal retained', $sell->fresh()->intent['rationale']);
    }

    public function test_orders_cannot_reserve_the_same_shares_and_cancelling_a_partial_sell_releases_the_remainder(): void
    {
        $buy = $this->submit();
        $this->travel(1)->seconds();
        $this->observe($buy);
        $sell = $this->submit(['side' => 'SELL', 'quantity' => '8', 'price_bound' => '90']);
        $overlap = $this->submit(['side' => 'SELL', 'quantity' => '3', 'price_bound' => '90']);
        $this->assertSame('rejected', $overlap->status);
        $this->assertSame('insufficient_unreserved_shares', SimulationEvent::latest('id')->first()->reason);
        $this->travel(1)->seconds();
        $this->observe($sell, ['bid' => '99', 'ask' => '100', 'bid_size' => '3']);
        app(OfflineSimulator::class)->cancel($this->connection, $this->connection->user_id, $sell->id);
        $this->decimal('7', SimulationPosition::sole()->quantity);
        $this->decimal('0', SimulationPosition::sole()->reserved_quantity);
    }

    public function test_report_is_scoped_escaped_and_keeps_unvalued_positions_and_returns_unknown(): void
    {
        $buy = $this->submit(['rationale' => '<script>alert("fixture")</script>']);
        $this->travel(1)->seconds();
        $this->observe($buy);
        $paper = app(PaperPolicies::class)->state($this->connection);
        $overview = app(SimulationOverview::class)->for($this->connection, $paper);
        $this->decimal('1001.1', $overview['cost_basis']);
        $this->decimal('0.1', $overview['fees']);
        $this->assertNull($overview['unrealized_pnl']);
        $this->assertNull($overview['total_return']);
        $foreign = McpFixture::connection();
        $this->assertNull(app(SimulationOverview::class)->for($foreign, $paper));
        $this->get('/mcp/dashboard/control')->assertRedirect('/login');
        $user = User::findOrFail($this->connection->user_id);
        $response = $this->actingAs($user)->get('/mcp/dashboard/control')->assertOk();
        $response->assertSee('Offline paper simulation')->assertSee('No broker-confirmed trades', false)
            ->assertSee('Unavailable — no current validated marks')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("fixture")</script>', false);
        // Rebinding hides the prior simulation, including its private rationale.
        $this->connection->update(['bound_account_id' => 'another-synthetic-binding']);
        $this->get('/mcp/dashboard/control')->assertOk()->assertDontSee('Original intent / fill assumptions')->assertDontSee('fixture', false);
    }

    public function test_boundary_rejects_floats_and_synthetic_intents_cannot_be_edited_after_acceptance(): void
    {
        try {
            $this->submit(['quantity' => 0.1]);
            $this->fail('Float quantity passed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }
        try {
            F::quote(['ask' => 100.0]);
            $this->fail('Float quote passed.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $order = $this->submit();
        $this->expectException(\LogicException::class);
        $order->update(['quantity' => '11']);
    }

    public function test_a_later_unmarketable_sample_prevents_replaying_an_older_favorable_tick(): void
    {
        $order = $this->submit();
        $this->travel(1)->seconds();
        $earlier = F::quote();
        $this->travel(1)->seconds();
        $this->observe($order, ['bid' => '104', 'ask' => '105']);
        app(OfflineSimulator::class)->observe($this->connection, $this->connection->user_id, $order->id, $earlier);
        $this->assertSame('observation_not_after_order', SimulationEvent::latest('id')->first()->reason);
        $this->assertSame('open', $order->fresh()->status);
    }

    public function test_pending_orders_in_other_symbols_consume_sleeve_and_cash_floor_and_missing_marks_are_unknown(): void
    {
        foreach (['FIRST', 'SECOND'] as $symbol) {
            $order = $this->submit(['symbol' => $symbol, 'quantity' => '19'], [], [$symbol => F::quote(['symbol' => $symbol])]);
            $this->assertSame('open', $order->status);
        }
        $order = $this->submit(['quantity' => '15']);
        $this->assertSame('rejected', $order->status);
        $this->assertSame('sleeve_limit_exceeded', SimulationEvent::latest('id')->first()->reason);
        $order = $this->submit(['sleeve' => 'long_term', 'quantity' => '19']);
        $this->assertSame('open', $order->status);
        $this->travel(1)->seconds();
        $this->observe($order);
        $new = $this->submit(['symbol' => 'THIRD'], [], ['THIRD' => F::quote(['symbol' => 'THIRD'])]);
        $this->assertSame('rejected', $new->status);
        $this->assertSame('position_mark_unknown', SimulationEvent::latest('id')->first()->reason);
    }

    public function test_daily_loss_pause_and_unknown_calendar_reject_new_buys_without_reserving(): void
    {
        $loss = $this->submit([], ['session_open_equity' => '11000']);
        $this->assertSame('rejected', $loss->status);
        $this->assertSame('scenario_daily_loss_pause', SimulationEvent::latest('id')->first()->reason);
        DB::table('mcp_market_sessions')->delete();
        $this->assertSame('rejected', $this->submit()->status);
        $this->assertSame('outside_verified_session', SimulationEvent::latest('id')->first()->reason);
        $this->decimal('0', $this->portfolio->fresh()->reserved_cash);
    }

    public function test_unresolved_corporate_action_stays_paused_when_a_later_quote_claims_clear(): void
    {
        $order = $this->submit();
        $this->travel(1)->seconds();
        $this->observe($order, ['corporate_actions_clear' => false]);
        $this->travel(1)->seconds();
        $this->observe($order);
        $this->assertSame('corporate_action_adjustment_required', SimulationEvent::latest('id')->first()->reason);
        $this->assertSame('open', $order->fresh()->status);
        $this->decimal('0', SimulationPosition::sole()->quantity);
        $this->assertSame('corporate_action_adjustment_required', SimulationPosition::sole()->blocked_reason);
    }

    public function test_fees_are_reserved_and_can_breach_the_cash_floor_even_when_notional_fits(): void
    {
        $draft = app(PaperMandates::class)->append($this->connection, $this->connection->user_id, 1,
            (string) Str::uuid(), array_replace($this->policy->snapshot['rules'], [
                'long_term_pct' => '0', 'active_pct' => '90', 'cash_pct' => '10', 'max_position_pct' => '100',
            ]), 'Synthetic fee/cash-floor scenario.');
        $this->policy = app(PaperPolicies::class)->apply($this->connection, $this->connection->user_id,
            $draft->id, 2, 1, (string) Str::uuid());
        $order = $this->submit(['quantity' => '90', 'price_bound' => '100']);
        $this->assertSame('rejected', $order->status);
        $this->assertSame('cash_floor_exceeded', SimulationEvent::latest('id')->first()->reason);
        $this->decimal('0', $this->portfolio->fresh()->reserved_cash);
    }
}
