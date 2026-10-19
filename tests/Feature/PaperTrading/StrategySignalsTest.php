<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategySignal;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Monitoring\ExternalOrders;
use App\Services\Monitoring\Monitor;
use App\Services\PaperTrading\PaperMarketReferences;
use App\Services\PaperTrading\StrategySignals;
use App\Services\PaperTrading\SymbolProfiles;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AlpacaReferenceFixture;
use Tests\Support\ExternalOrderGateway;
use Tests\Support\StrategySignalFixture as F;
use Tests\TestCase;

class StrategySignalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    public function test_fresh_eligible_breakout_records_encrypted_signal_without_trading_and_duplicate_cycle_reuses_it(): void
    {
        [$c, $p] = F::seed();
        $before = $p->fresh()->getAttributes();
        $network = count(Http::recorded());
        $row = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        $this->assertSame(['signal', 'BUY', 'breakout_trigger_crossed'], [$row->status, $row->side, $row->reason], json_encode($row->payload));
        $this->assertNotNull($row->profile_id);
        $this->assertNotNull($row->watchlist_revision_id);
        $this->assertNotNull($row->market_input_id);
        $this->assertSame($before, $p->fresh()->getAttributes());
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        $this->assertCount($network, Http::recorded());
        $this->assertStringNotContainsString('entry_atr', DB::table('mcp_strategy_signals')->value('payload'));
        $this->travel(1)->seconds();
        $again = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        $this->assertSame($row->id, $again->id);
        $this->assertSame($row->input_fingerprint, $again->input_fingerprint);
        $this->expectException(\LogicException::class);
        $row->delete();
    }

    private function demo($c)
    {
        return app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
    }

    private function nextCycle($c): void
    {
        $this->travel(5)->minutes();
        app(Monitor::class)->sync($c->id, 'refresh:'.Str::uuid());
        F::quote($c);
    }

    private function held($c, $p, $policy, $signal): void
    {
        $this->travel(1)->seconds();
        $order = SimulationOrder::create([
            'paper_portfolio_id' => $p->id, 'policy_application_id' => $policy->id, 'actor_id' => $c->user_id,
            'request_id' => (string) Str::uuid(), 'intent_hash' => str_repeat('a', 64),
            'intent' => ['strategy_signal_id' => $signal->id, 'origin' => 'real_data_paper_simulation'],
            'assumptions' => [], 'auth_generation' => $c->auth_generation, 'symbol' => 'DEMO', 'sleeve' => 'active',
            'side' => 'BUY', 'type' => 'limit', 'status' => 'filled', 'quantity' => '1', 'filled_quantity' => '1',
            'price_bound' => '102', 'accepted_at' => now(), 'expires_at' => now()->addHour()]);
        SimulationEvent::create(['sim_order_id' => $order->id, 'event_key' => 'synthetic-fill',
            'kind' => 'fill', 'quantity' => '1', 'price' => '101.60', 'ledger_version' => $p->ledger_version,
            'evidence' => [], 'recorded_at' => now()]);
        SimulationPosition::create(['paper_portfolio_id' => $p->id, 'symbol' => 'DEMO', 'sleeve' => 'active',
            'quantity' => '1', 'cost_basis' => '101.60']);
    }

    public function test_stale_missing_future_and_old_authorization_quotes_never_signal(): void
    {
        [$c] = F::seed();
        $this->travel(61)->seconds();
        $this->assertSame('unavailable', $this->demo($c)->status);
        $this->nextCycle($c);
        F::quote($c, ['quote.bid_as_of' => now()->addSecond()->toISOString()]);
        $this->assertSame('unavailable', $this->demo($c)->status);
        $this->nextCycle($c);
        F::quote($c, ['auth_generation' => $c->auth_generation + 1]);
        $this->assertSame('unavailable', $this->demo($c)->status);
        $this->assertSame(0, StrategySignal::where('status', 'signal')->count());
    }

    public function test_opening_delay_regular_session_and_missing_calendar_are_enforced(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T13:40:00Z'));
        [$c, , , , $lists] = F::seed();
        $this->assertSame('opening_entry_delay', $this->demo($c)->reason);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T20:00:00Z'));
        $lists->collect($c, $c->user_id, (string) Str::uuid());
        $this->assertSame('session_after_close', $this->demo($c)->reason);
        $this->travel(5)->minutes();
        DB::table('mcp_market_sessions')->where('session_date', '2026-10-19')->delete();
        $this->assertSame('session_unknown', $this->demo($c)->reason);
    }

    public function test_removal_external_incident_and_disabled_entry_rules_do_not_suppress_held_stop_exit(): void
    {
        [$c, $p, $policy, , $lists, $gateway] = F::seed();
        $buy = $this->demo($c);
        $this->held($c, $p, $policy, $buy);
        $gateway->members = [];
        $lists->collect($c, $c->user_id, (string) Str::uuid());
        $external = new ExternalOrderGateway;
        $external->orders = [['id' => 'synthetic-external']];
        $this->app->instance(BrokerGateway::class, $external);
        $this->nextCycle($c);
        config(['paper.rule_eligibility_enabled' => false, 'paper.watchlist_intake_enabled' => false, 'robinhood_mcp.earnings_enabled' => false]);
        F::quote($c, ['quote.bid' => '97', 'quote.ask' => '97.1']);
        $row = $this->demo($c);
        $this->assertSame(['signal', 'SELL', 'initial_stop_hit'], [$row->status, $row->side, $row->reason]);
        $this->assertSame('external_orders_review_required', app(ExternalOrders::class)->entryReason($c));
        $this->assertSame('99.600000000000000000', $row->payload['result']['facts']['initial_stop']);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 1); // only the explicit synthetic fixture
        $this->nextCycle($c);
        $this->assertSame('exit_remains_pending', $this->demo($c)->reason);
    }

    public function test_external_order_pause_blocks_new_entries_and_cannot_be_bypassed_by_disabled_detection(): void
    {
        [$c] = F::seed();
        $external = new ExternalOrderGateway;
        $external->orders = [['id' => 'synthetic-external']];
        $this->app->instance(BrokerGateway::class, $external);
        app(Monitor::class)->sync($c->id, 'paused:'.Str::uuid());
        $this->assertSame('eligibility_unavailable', $this->demo($c)->reason);
        $this->nextCycle($c);
        config(['mcp.external_order_detection_enabled' => false]);
        $this->assertSame('external_order_detection_disabled', $this->demo($c)->reason);
    }

    public function test_unknown_legacy_position_is_not_assigned_an_invented_stop(): void
    {
        [$c, $p] = F::seed();
        SimulationPosition::create(['paper_portfolio_id' => $p->id, 'symbol' => 'DEMO', 'sleeve' => 'active', 'quantity' => '1', 'cost_basis' => '100']);
        $this->assertSame('strategy_position_history_unverified', $this->demo($c)->reason);
    }

    public function test_unknown_earnings_and_new_reference_reads_require_new_entry_evidence(): void
    {
        [$c] = F::seed();
        config(['robinhood_mcp.earnings_enabled' => false]);
        $this->assertSame('earnings_date_unknown', $this->demo($c)->reason);
        $this->nextCycle($c);
        config(['robinhood_mcp.earnings_enabled' => true]);
        app(PaperMarketReferences::class)->collect($c, $c->user_id, [], (string) Str::uuid());
        $this->assertSame('current_profile_unavailable', $this->demo($c)->reason);
    }

    public function test_atomic_crash_rolls_back_the_cycle_and_retry_recovers(): void
    {
        [$c] = F::seed();
        $crash = true;
        $created = 0;
        StrategySignal::creating(function () use (&$crash, &$created) {
            if ($crash && ++$created === 2) {
                throw new \RuntimeException('synthetic crash');
            }
        });
        try {
            $this->demo($c);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic crash', $e->getMessage());
        } finally {
            $crash = false;
        }
        $this->assertDatabaseCount('mcp_strategy_cycles', 0);
        $this->assertDatabaseCount('mcp_strategy_signals', 0);
        $this->assertSame('BUY', $this->demo($c)->side);
    }

    public function test_gate_actor_and_account_changes_are_refused(): void
    {
        [$c] = F::seed();
        try {
            app(StrategySignals::class)->record($c, $c->user_id + 1);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        config(['paper.signals_enabled' => false]);
        $this->assertSame(0, app(StrategySignals::class)->tick());
        $this->artisan('mcp:strategy-cycle', ['--once' => true])->expectsOutputToContain('disabled')->assertSuccessful();
        config(['paper.signals_enabled' => true]);
        $this->artisan('mcp:strategy-cycle', ['--once' => true, '--account' => (string) $c->id])->expectsOutputToContain('breakout_trigger_crossed')->assertSuccessful();
        $c->increment('auth_generation');
        $stale = clone $c;
        $stale->auth_generation--;
        try {
            app(StrategySignals::class)->record($stale, $c->user_id);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_data_only_members_warm_up_but_never_generate_an_entry_signal(): void
    {
        [$c, , , , $lists, $gateway] = F::seed();
        $gateway->lists = ['Strategy Candidates' => ['id' => 'warm', 'members' => $gateway->members], 'Core candidates' => ['id' => 'core', 'members' => []]];
        $lists->configure($c, $c->user_id, 1, (string) Str::uuid(), [['name' => 'Strategy Candidates', 'role' => 'data_only'], ['name' => 'Core candidates', 'role' => 'nominate']]);
        $lists->collect($c, $c->user_id, (string) Str::uuid());
        $row = $this->demo($c);
        $this->assertSame('unavailable', $row->status);
        $this->assertSame('unavailable', $row->payload['evidence']['report']['eligibility']['checks']['membership']['status']);
    }

    public function test_earnings_last_session_exit_uses_current_calendar_and_entries_honor_the_blackout(): void
    {
        [$c, $p, $policy] = F::seed();
        $buy = $this->demo($c);
        $this->held($c, $p, $policy, $buy);
        $this->nextCycle($c);
        F::earnings($c, [['DEMO', 2026, 3, '2026-10-20', 'am', false, null, null]]);
        $row = $this->demo($c);
        $this->assertSame(['SELL', 'last_session_before_earnings'], [$row->side, $row->reason]);
    }

    public function test_reentry_cooldown_starts_at_the_flattening_fill_and_blocks_a_new_entry(): void
    {
        [$c, $p, $policy] = F::seed();
        $this->held($c, $p, $policy, $this->demo($c));
        $sell = SimulationOrder::sole()->replicate();
        $sell->request_id = (string) Str::uuid();
        $sell->side = 'SELL';
        $sell->save();
        SimulationEvent::create(['sim_order_id' => $sell->id, 'event_key' => 'flat-fill', 'kind' => 'fill',
            'quantity' => '1', 'price' => '102', 'ledger_version' => $p->ledger_version, 'evidence' => [], 'recorded_at' => now()]);
        SimulationPosition::sole()->update(['quantity' => '0', 'cost_basis' => '0']);
        $this->nextCycle($c);
        $this->assertSame('reentry_cooldown', $this->demo($c)->reason);
    }

    public function test_signal_history_page_is_authenticated_and_scoped_to_its_paper_portfolio(): void
    {
        [$c] = F::seed();
        $this->demo($c);
        $this->get('/mcp/dashboard/control')->assertRedirect('/login');
        $this->actingAs(User::findOrFail($c->user_id))->get('/mcp/dashboard/control')
            ->assertOk()->assertSee('Strategy signal history')->assertSee('breakout_trigger_crossed')->assertSee('Paper evaluation only');
        $this->actingAs(User::factory()->create())->get('/mcp/dashboard/control')->assertForbidden();
    }

    private function dailyClose($c, int $lastClose): void
    {
        $rising = fn ($n, $from, $step) => array_map(fn ($i) => $from + $i * $step, range(0, $n - 1));
        AlpacaReferenceFixture::fake(fn () => AlpacaReferenceFixture::body([
            'DEMO' => AlpacaReferenceFixture::series([...$rising(274, 5000, 20), $lastClose]),
            'SPY' => AlpacaReferenceFixture::series($rising(275, 30000, 10)),
        ]), ['DEMO' => AlpacaReferenceFixture::asset('DEMO')]);
        app(PaperMarketReferences::class)->collect($c, $c->user_id, [], (string) Str::uuid());
        app(SymbolProfiles::class)->record($c, $c->user_id, ['DEMO'], (string) Str::uuid());
    }

    public function test_trailing_stop_rises_on_completed_closes_and_cannot_loosen_on_a_later_revision(): void
    {
        [$c, $p, $policy, , $lists] = F::seed();
        $this->held($c, $p, $policy, $this->demo($c));
        $this->travelTo(CarbonImmutable::parse('2026-10-20T14:00:00Z'));
        $lists->collect($c, $c->user_id, (string) Str::uuid());
        F::earnings($c);
        $this->dailyClose($c, 15000);
        app(Monitor::class)->sync($c->id, 'next-day:'.Str::uuid());
        F::quote($c, ['quote.bid' => '150', 'quote.ask' => '150.1']);
        $row = $this->demo($c);
        $this->assertSame('wait', $row->status, json_encode($row->payload));
        $this->assertSame('147.00000000', $row->payload['result']['facts']['trailing_stop']);
        $this->nextCycle($c);
        $this->dailyClose($c, 14000);
        F::quote($c, ['quote.bid' => '146', 'quote.ask' => '146.1']);
        $row = $this->demo($c);
        $this->assertSame(['SELL', 'trailing_stop_hit'], [$row->side, $row->reason]);
        $this->assertSame('147.00000000', $row->payload['result']['facts']['trailing_stop']);
    }
}
