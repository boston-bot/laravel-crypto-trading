<?php

namespace Tests\Integration;

use App\Models\Execution\BrokerOrderBinding;
use App\Models\Execution\OrderIntent;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\User;
use App\Services\Execution\AutomaticTrading;
use App\Services\Execution\ExecutionControls;
use App\Services\Execution\ExecutionFailure;
use App\Services\Execution\ExecutionGateway;
use App\Services\Execution\ExecutionJournal;
use App\Services\Execution\LivePolicies;
use App\Services\Execution\LiveTradingCycle;
use App\Services\Execution\SubmissionChecks;
use App\Services\Monitoring\AccountScope;
use App\Services\Operations\OperationsHealth;
use App\Services\PaperTrading\StrategySignals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AutomaticTradingFixture as A;
use Tests\Support\ExecutionJournalFixture;
use Tests\Support\FakeExecutionGateway;
use Tests\Support\LiveBrokerGateway;
use Tests\Support\LiveExecutionFixture as F;
use Tests\TestCase;

class AutomaticTradingTest extends TestCase
{
    public function test_missing_cycles_raise_operational_health_alert_and_off_disables_the_expectation(): void
    {
        [$c] = $this->ready();
        $this->assertSame('ok', app(OperationsHealth::class)->evaluate()['automatic_trading']['status']);
        $this->travel(11)->minutes();
        $this->assertSame('critical', app(OperationsHealth::class)->evaluate()['automatic_trading']['status']);
        app(AutomaticTrading::class)->set($c, $c->user_id, (string) Str::uuid(), false, 0);
        $this->assertSame('disabled', app(OperationsHealth::class)->evaluate()['automatic_trading']['status']);
    }

    public function test_schedule_gives_live_orchestrator_collection_ownership_without_legacy_or_ai_jobs(): void
    {
        $this->ready(false);
        config(['paper.signals_enabled' => true, 'paper.signal_handoff_enabled' => true, 'paper.real_data_fills_enabled' => true]);
        require base_path('routes/console.php');
        $commands = collect(Schedule::events())->pluck('command')->implode("\n");
        $this->assertStringContainsString('mcp:live-cycle --once', $commands);
        $event = collect(Schedule::events())->first(fn ($e) => str_contains($e->command ?? '', 'mcp:live-cycle --once'));
        $this->assertSame('1-59/5 * * * *', $event->expression);
        $this->assertTrue($event->runInBackground);
        foreach (['mcp:signal-paper-cycle', 'mcp:market-cycle', 'mcp:paper-collect', 'mcp:paper-decisions', 'mcp:research'] as $other) {
            $this->assertStringNotContainsString($other, $commands);
        }
    }

    public function test_ui_start_requires_owner_and_confirmations_stop_stays_available_and_pause_preserves_stop(): void
    {
        [$c] = $this->ready(false);
        $scope = AccountScope::key($c);
        $body = ['account_scope' => $scope, 'request_id' => (string) Str::uuid(), 'base_version' => 0,
            'confirm_automatic' => '1', 'confirm_exclusive_executor' => '1'];
        $this->post(route('mcp.live.automatic.start'), $body)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post(route('mcp.live.automatic.start'), $body)->assertForbidden();
        $this->actingAs(User::findOrFail($c->user_id));
        $this->postJson(route('mcp.live.automatic.start'), array_replace($body, ['confirm_exclusive_executor' => null]))->assertUnprocessable();
        $this->postJson(route('mcp.live.automatic.start'), array_replace($body, ['account_scope' => str_repeat('0', 64)]))->assertConflict();
        $this->post(route('mcp.live.automatic.start'), $body)->assertRedirect();
        $this->get('/mcp/dashboard')->assertOk()->assertSee('On · buys and exits')->assertSee('Stop all submissions');
        $this->assertSame([], $this->gateway->calls, 'Arming never submits an order in the request.');
        $this->post(route('mcp.live.buys.pause'), ['account_scope' => $scope, 'request_id' => (string) Str::uuid(), 'paused' => '1'])->assertRedirect();
        $this->get('/mcp/dashboard')->assertOk()->assertSee('Exits only · buys paused');
        // Exhausting the start throttle must not throttle STOP.
        for ($i = 0; $i < 12; $i++) {
            $this->post(route('mcp.live.automatic.start'), array_replace($body, ['request_id' => (string) Str::uuid()]));
        }
        $stopRequest = (string) Str::uuid();
        $this->post(route('mcp.live.automatic.stop'), ['account_scope' => $scope, 'request_id' => $stopRequest])->assertRedirect();
        $this->post(route('mcp.live.buys.pause'), ['account_scope' => $scope, 'request_id' => (string) Str::uuid(), 'paused' => '0'])->assertRedirect();
        $this->assertTrue(ExecutionControls::state($scope)['emergency_disabled'], 'Resuming buys must not release a full stop.');
        $this->assertFalse(AutomaticTrading::current($scope)->enabled);
        $this->get('/mcp/dashboard')->assertOk()->assertSee('Off / blocked');
        app(AutomaticTrading::class)->set($c, $c->user_id, (string) Str::uuid(), true, 2, true);
        $this->post(route('mcp.live.automatic.stop'), ['account_scope' => $scope, 'request_id' => $stopRequest])->assertRedirect();
        $this->assertFalse(AutomaticTrading::current($scope)->enabled, 'An old OFF retry cannot falsely report stopped after a later ON.');
        $this->assertTrue(ExecutionControls::state($scope)['emergency_disabled']);
        app()->instance('env', 'local');
        $this->post(route('mcp.live.automatic.stop'), ['account_scope' => $scope, 'request_id' => (string) Str::uuid()])->assertStatus(419);
    }

    public function test_failed_reconciliation_and_stale_quotes_cannot_submit(): void
    {
        [$c] = $this->ready();
        $this->broker->partialOrders = true;
        $this->assertSame(0, app(LiveTradingCycle::class)->tick($c->id)['attempted']);
        $this->assertSame('reconciliation_unavailable', DB::table('mcp_execution_cycles')->sole()->reason);
        $this->travel(5)->minutes();
        $this->broker->partialOrders = false;
        A::batch($c);
        $this->travel(31)->seconds();
        $this->assertSame(0, app(LiveTradingCycle::class)->tick($c->id)['attempted']);
        $this->assertSame([], $this->gateway->calls);
        $this->assertSame(0, OrderIntent::where('mcp_connection_id', $c->id)->count());
    }

    public function test_ambiguous_submission_blocks_later_cycles_without_replaying(): void
    {
        [$c] = $this->ready();
        $this->gateway->place = new ExecutionFailure('synthetic_lost_response', true);
        $this->assertSame(1, app(LiveTradingCycle::class)->tick($c->id)['attempted']);
        $this->assertSame('unknown', DB::table('mcp_execution_dispatches')->sole()->status);
        $this->travel(5)->minutes();
        A::batch($c);
        $this->assertSame(0, app(LiveTradingCycle::class)->tick($c->id)['attempted']);
        $this->assertCount(3, $this->gateway->calls);
        $this->assertSame(1, OrderIntent::where('mcp_connection_id', $c->id)->count());
    }

    private ?int $fixture = null;

    private LiveBrokerGateway $broker;

    private FakeExecutionGateway $gateway;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        $this->gateway = new FakeExecutionGateway;
        app()->instance(ExecutionGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        ExecutionJournalFixture::cleanup($this->fixture);
        parent::tearDown();
    }

    private function ready(bool $arm = true): array
    {
        $this->broker = new LiveBrokerGateway;
        [$c, $signal] = F::seed($this->broker);
        $this->fixture = $c->id;
        A::configure();
        $activation = $arm ? app(AutomaticTrading::class)->set($c, $c->user_id, (string) Str::uuid(), true, 0, true) : null;
        A::batch($c);

        return [$c, $activation, $signal];
    }

    public function test_reconcile_first_cycle_dispatches_one_live_signal_and_durable_slot_prevents_restart_replay(): void
    {
        [$c] = $this->ready();
        $before = DB::table('mcp_paper_portfolios')->where('mcp_connection_id', $c->id)->first();
        $this->assertSame(['cycles' => 1, 'attempted' => 1, 'failed' => 0], app(LiveTradingCycle::class)->tick($c->id));
        $intent = OrderIntent::where('mcp_connection_id', $c->id)->sole();
        $this->assertSame('live', $intent->payload['position_source']);
        $this->assertSame('accepted', DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->value('status'));
        $this->assertSame(['open', 'review', 'place:'.$intent->idempotency_key], $this->gateway->calls);
        $this->assertSame('automatic_execution', DB::table('mcp_runs')->where('dedup_key', 'like', 'automatic:%')->value('trigger'));
        $this->assertEquals($before, DB::table('mcp_paper_portfolios')->where('mcp_connection_id', $c->id)->first());
        $this->assertSame(['cycles' => 0, 'attempted' => 0, 'failed' => 0], (new LiveTradingCycle)->tick($c->id));
        $this->assertCount(3, $this->gateway->calls);
    }

    public function test_unarmed_and_default_disabled_never_submit_even_with_valid_entry_data(): void
    {
        [$c] = $this->ready(false);
        $this->assertSame(0, app(LiveTradingCycle::class)->tick($c->id)['attempted']);
        config(['mcp.live_automation_enabled' => false]);
        $this->artisan('mcp:live-cycle', ['--once' => true, '--account' => $c->id])->assertSuccessful();
        $this->assertSame([], $this->gateway->calls);
        $this->assertSame(0, OrderIntent::where('mcp_connection_id', $c->id)->count());
    }

    public function test_stop_during_preview_withdraws_before_place_and_replaying_old_on_cannot_resume(): void
    {
        [$c, $on] = $this->ready();
        $this->gateway->beforeReview = fn () => app(AutomaticTrading::class)->set($c, $c->user_id, (string) Str::uuid(), false, 0);
        $this->assertSame(1, app(LiveTradingCycle::class)->tick($c->id)['attempted']);
        $this->assertSame(['open', 'review'], $this->gateway->calls);
        $this->assertSame('withdrawn', DB::table('mcp_execution_dispatches')->sole()->status);
        app(AutomaticTrading::class)->set($c, $c->user_id, $on->request_id, true, 0, true);
        $this->assertFalse(AutomaticTrading::current(AccountScope::key($c))->enabled);
        $this->assertTrue(ExecutionControls::state(AccountScope::key($c))['emergency_disabled']);
        $this->assertFalse(app(AutomaticTrading::class)->reason($c) === null);
    }

    public function test_off_is_available_after_disconnect_with_stale_version_and_damaged_checkpoint(): void
    {
        [$c] = $this->ready();
        $c->update(['status' => 'reconnect_required', 'auth_generation' => $c->auth_generation + 1]);
        foreach (glob(config('mcp.execution_recovery_dir').'/*.json') as $file) {
            unlink($file);
        }
        $off = app(AutomaticTrading::class)->set($c, $c->user_id, (string) Str::uuid(), false, 0);
        $this->assertFalse($off->enabled);
        $this->assertTrue(ExecutionControls::state(AccountScope::key($c))['emergency_disabled']);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_paper_position_and_open_paper_order_do_not_control_live_entry_evaluation(): void
    {
        [$c] = $this->ready();
        $portfolio = DB::table('mcp_paper_portfolios')->where('mcp_connection_id', $c->id)->first();
        SimulationPosition::create(['paper_portfolio_id' => $portfolio->id, 'symbol' => 'DEMO', 'sleeve' => 'active', 'quantity' => '100',
            'reserved_quantity' => '0', 'cost_basis' => '10000', 'realized_pnl' => '0', 'updated_at' => now()]);
        $signal = app(StrategySignals::class)->recordLive($c, $c->user_id, ['DEMO'])->sole();
        $this->assertSame(['live', 'signal', 'BUY'], [$signal->position_source, $signal->status, $signal->side]);
    }

    public function test_policy_or_authorization_change_invalidates_automation_and_old_signals_do_not_rearm(): void
    {
        [$c, $on] = $this->ready();
        $signal = app(StrategySignals::class)->recordLive($c, $c->user_id, ['DEMO'])->sole();
        $this->travel(1)->seconds();
        app(AutomaticTrading::class)->set($c, $c->user_id, (string) Str::uuid(), false, 0);
        $new = app(AutomaticTrading::class)->set($c, $c->user_id, (string) Str::uuid(), true, 2, true);
        try {
            F::stage($c, $signal); // Manual staging remains separate; automatic admission below must reject the old signal.
            app(ExecutionJournal::class)->stage($c, $c->user_id, $signal->id,
                app(SubmissionChecks::class)->plan($c, $signal)['draft'] + ['expires_at' => now()->addMinutes(4)->toISOString()], $new->id);
            $this->fail('A signal recorded before reactivation was admitted.');
        } catch (HttpException $error) {
            $this->assertSame('automatic_signal_predates_activation', $error->getMessage());
        }
        app(LivePolicies::class)->apply($c, $c->user_id, 1, (string) Str::uuid(), F::POLICY);
        $this->assertSame('automatic_authorization_or_policy_changed', app(AutomaticTrading::class)->reason($c));
        $this->assertSame(0, app(LiveTradingCycle::class)->tick($c->id)['attempted']);
    }

    public function test_live_fill_gets_an_automatic_exit_even_when_buys_paused_and_watchlist_expired(): void
    {
        [$c] = $this->ready();
        $this->assertSame(1, app(LiveTradingCycle::class)->tick($c->id)['attempted']);
        $intent = OrderIntent::where('mcp_connection_id', $c->id)->sole();
        $orderId = BrokerOrderBinding::where('intent_id', $intent->id)->sole()->broker_order_id;
        $this->travel(5)->minutes();
        $this->broker->orders = [LiveBrokerGateway::order($orderId, 'filled', $intent->payload['quantity'], $intent->payload['quantity'],
            [['automatic-entry-fill', $intent->payload['quantity'], '101.70']])];
        $this->broker->positions['DEMO'] = [$intent->payload['quantity'], '101.70'];
        F::sync($c, $this->broker);
        app(ExecutionControls::class)->pauseBuys($c, $c->user_id, (string) Str::uuid(), true);
        config(['paper.watchlist_intake_enabled' => false]);
        A::batch($c, '70', '70.01');
        $this->gateway->calls = [];
        $this->assertSame(['cycles' => 1, 'attempted' => 1, 'failed' => 0], app(LiveTradingCycle::class)->tick($c->id));
        $sell = OrderIntent::where('mcp_connection_id', $c->id)->latest('id')->first();
        $this->assertSame('SELL', $sell->payload['side']);
        $this->assertSame($intent->payload['quantity'], $sell->payload['quantity']);
        $this->assertCount(3, $this->gateway->calls);
    }
}
