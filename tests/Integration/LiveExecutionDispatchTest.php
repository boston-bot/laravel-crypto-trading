<?php

namespace Tests\Integration;

use App\Models\Execution\BrokerOrderBinding;
use App\Models\Execution\OrderIntent;
use App\Services\Execution\ExecutionControls;
use App\Services\Execution\ExecutionDispatcher;
use App\Services\Execution\ExecutionFailure;
use App\Services\Execution\ExecutionGateway;
use App\Services\Execution\ExecutionJournal;
use App\Services\Execution\LivePolicies;
use App\Services\Execution\LivePositionFacts;
use App\Services\Execution\SubmissionChecks;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\SignalPaperHandoff;
use App\Services\PaperTrading\StrategySignals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ExecutionJournalFixture;
use Tests\Support\FakeExecutionGateway;
use Tests\Support\LiveBrokerGateway;
use Tests\Support\LiveExecutionFixture as F;
use Tests\Support\SignalExecutionFixture;
use Tests\TestCase;

/** The dispatcher with real claims and a scripted fake gateway. No broker contact. */
class LiveExecutionDispatchTest extends TestCase
{
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

    private function ready(): array
    {
        $this->broker = new LiveBrokerGateway;
        [$c, $signal] = F::seed($this->broker);
        $this->fixture = $c->id;
        config(['mcp.brokerage_writes_enabled' => true]); // Test-only, with a fake gateway.

        return [$c, F::stage($c, $signal)];
    }

    private function journalState(OrderIntent $intent): array
    {
        return [DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->value('status'),
            DB::table('mcp_execution_events')->where('intent_id', $intent->id)->orderByDesc('version')->value('reason')];
    }

    public function test_order_and_approval_results_are_journaled_with_the_intent_ref_id(): void
    {
        [$c, $intent] = $this->ready();
        $this->assertSame('accepted', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $this->assertSame(['open', 'review', 'place:'.$intent->idempotency_key], $this->gateway->calls);
        $this->assertSame('submission_response', BrokerOrderBinding::where('intent_id', $intent->id)->value('source'));
        $this->assertSame('not_claimed', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $this->assertCount(3, $this->gateway->calls, 'A dispatched intent is never sent again.');
    }

    public function test_approval_result_blocks_the_account_as_approval_pending(): void
    {
        [$c, $intent] = $this->ready();
        $this->gateway->place = ['kind' => 'approval', 'approval_id' => 'approval-d', 'state' => 'APPROVAL_STATE_PENDING', 'is_edited' => false,
            'expires_at' => now()->addMinutes(30)->toIso8601String()];
        $this->assertSame('approval_pending', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $this->assertSame(['approval_pending', 'broker_customer_approval_requested'], $this->journalState($intent));
        $other = F::stage($c, F::signal($c, 'BUY'));
        $this->assertSame('not_claimed', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $other->id));
    }

    public function test_review_alerts_or_an_approval_mode_mismatch_withdraw_without_placing(): void
    {
        [$c, $intent] = $this->ready();
        $this->gateway->review = ['order_checks' => ['buying_power' => ['alert' => 'insufficient']], 'customer_approval_required' => true];
        $this->assertSame('withdrawn', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $this->assertSame(['withdrawn', 'withdrawn_broker_order_checks'], $this->journalState($intent));
        $this->gateway->review = ['order_checks' => [], 'customer_approval_required' => false];
        $second = F::stage($c, F::signal($c, 'BUY'));
        $this->assertSame('withdrawn', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $second->id));
        $this->assertSame(['withdrawn', 'withdrawn_broker_approval_mode_differs'], $this->journalState($second));
        $this->assertSame(['open', 'review', 'open', 'review'], $this->gateway->calls);
    }

    public function test_emergency_disable_during_review_withdraws_before_the_place_request(): void
    {
        [$c, $intent] = $this->ready();
        $this->gateway->beforeReview = fn () => app(ExecutionControls::class)->set($c, $c->user_id, (string) Str::uuid(), false, true, 'Emergency during dispatch.');
        $this->assertSame('withdrawn', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $this->assertSame(['withdrawn', 'withdrawn_emergency_disabled'], $this->journalState($intent));
        $this->assertNotContains('place:'.$intent->idempotency_key, $this->gateway->calls);
    }

    public function test_failures_before_sending_withdraw_and_failures_after_sending_are_unknown_until_ref_id_proof(): void
    {
        [$c, $intent] = $this->ready();
        $this->gateway->openFailure = new ExecutionFailure('write_schema_changed', false);
        $this->assertSame('withdrawn', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $this->assertSame(['withdrawn', 'withdrawn_write_schema_changed'], $this->journalState($intent));
        $this->gateway->openFailure = null;
        $this->gateway->place = new ExecutionFailure('place_outcome_unknown', true);
        $second = F::stage($c, F::signal($c, 'BUY'));
        $this->assertSame('unknown', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $second->id));
        $this->assertSame(['unknown', 'submission_unknown_place_outcome_unknown'], $this->journalState($second));
        // The broker had accepted it: the next order-history read proves it by ref_id. Nothing was resent.
        $this->travel(1)->seconds();
        $this->broker->orders = [LiveBrokerGateway::order('rh-timeout', 'queued', '1', ref: $second->idempotency_key)];
        F::sync($c, $this->broker);
        $this->assertSame('accepted', $this->journalState($second)[0]);
        $this->assertSame(1, count(array_filter($this->gateway->calls, fn ($call) => str_starts_with($call, 'place:'))));
    }

    public function test_writes_disabled_or_failing_checks_never_open_a_write_session(): void
    {
        [$c, $intent] = $this->ready();
        config(['mcp.brokerage_writes_enabled' => false]);
        $this->assertSame(409, $this->refusal(fn () => app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id)));
        config(['mcp.brokerage_writes_enabled' => true]);
        app(ExecutionControls::class)->set($c, $c->user_id, (string) Str::uuid(), true, false, 'Buy pause.');
        $this->assertSame(409, $this->refusal(fn () => app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id)));
        $this->assertSame([], $this->gateway->calls);
        $this->assertSame('staged', $this->journalState($intent)[0]);
    }

    public function test_unknown_submission_keeps_only_fixed_failure_categories_and_never_retries(): void
    {
        [$c, $intent] = $this->ready();
        $this->gateway->place = new ExecutionFailure('place_order_differs', true);
        $this->assertSame('unknown', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $this->assertSame(['unknown', 'submission_unknown_place_order_differs'], $this->journalState($intent));
        $this->assertSame('not_claimed', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $this->assertCount(3, $this->gateway->calls);
        // Arbitrary exception text is never journaled, even if wrapped as an ExecutionFailure.
        $dispatch = DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->first();
        app(ExecutionJournal::class)->uncertain($c, $c->user_id, $intent->id, $dispatch->claim_token, 'secret-broker-response');
        $this->assertFalse(DB::table('mcp_execution_events')->where('reason', 'like', '%secret%')->exists());
    }

    public function test_operator_command_stages_and_dispatches_one_signal_and_reports_denials(): void
    {
        [$c, $intent] = $this->ready();
        $signal = F::signal($c, 'BUY');
        $this->artisan('mcp:live-order', ['--account' => $c->id, '--signal' => $signal->id, '--stage-only' => true])->assertSuccessful();
        $staged = OrderIntent::where('signal_id', $signal->id)->sole();
        $this->assertSame('staged', $this->journalState($staged)[0]);
        $this->assertSame([], $this->gateway->calls);
        $this->artisan('mcp:live-order', ['--account' => $c->id, '--signal' => $signal->id])->assertSuccessful();
        $this->assertSame('accepted', $this->journalState($staged)[0]);
        app(ExecutionControls::class)->set($c, $c->user_id, (string) Str::uuid(), false, true, 'Stop.');
        $this->artisan('mcp:live-order', ['--account' => $c->id, '--signal' => F::signal($c, 'BUY')->id])->expectsOutputToContain('execution_emergency_disabled')->assertFailed();
        $this->artisan('mcp:live-order', ['--account' => $c->id, '--signal' => 999999])->assertFailed();
    }

    private function refusal(callable $work): int
    {
        try {
            $work();
            $this->fail('Expected refusal.');
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public static function previewChanges(): array
    {
        return array_map(fn ($case) => [$case], ['policy', 'buy_pause', 'quote_stale', 'buying_power', 'approval', 'account_revoked', 'account_read_failed']);
    }

    #[DataProvider('previewChanges')]
    public function test_all_admission_checks_run_again_after_preview(string $change): void
    {
        [$c, $intent] = $this->ready();
        $this->gateway->beforeReview = function () use ($c, $change) {
            if ($change === 'policy') {
                app(LivePolicies::class)->apply($c, $c->user_id, 1, (string) Str::uuid(), ['max_order_notional' => '100'] + F::POLICY);
            } elseif ($change === 'buy_pause') {
                app(ExecutionControls::class)->set($c, $c->user_id, (string) Str::uuid(), true, false, 'Pause during preview.');
            } elseif ($change === 'quote_stale') {
                $this->travel(31)->seconds();
            } else {
                match ($change) {
                    'buying_power' => $this->broker->buyingPower = '1',
                    'approval' => $this->broker->approvalRequired = false,
                    'account_revoked' => $this->broker->accountPatch = ['agentic_allowed' => false],
                    'account_read_failed' => $this->broker->accountPatch = null,
                };
                F::sync($c, $this->broker);
            }
        };
        $this->assertSame('withdrawn', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $this->assertSame(['open', 'review'], $this->gateway->calls, 'A preview never exempts the order from current admission checks.');
        $this->assertStringStartsWith('withdrawn_', $this->journalState($intent)[1]);
    }

    public function test_live_exit_uses_actual_fills_ignores_paper_state_and_survives_restart_and_watchlist_failure(): void
    {
        [$c, $intent] = $this->ready();
        $before = DB::table('mcp_paper_portfolios')->where('mcp_connection_id', $c->id)->first();
        $this->assertSame('accepted', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $orderId = BrokerOrderBinding::where('intent_id', $intent->id)->sole()->broker_order_id;
        $this->travel(1)->seconds();
        $this->broker->orders = [LiveBrokerGateway::order($orderId, 'filled', $intent->payload['quantity'], $intent->payload['quantity'],
            [['live-entry', $intent->payload['quantity'], '101.70']])];
        $this->broker->positions['DEMO'] = [$intent->payload['quantity'], '101.70'];
        F::sync($c, $this->broker);
        $facts = app(LivePositionFacts::class)->inspect($c, 'DEMO', CarbonImmutable::now());
        $this->assertSame('101.700000000000000000', $facts['context']['entry_price']);
        $this->assertSame($intent->id, $facts['context']['entry_order_id']);
        $this->assertSame(0, DB::table('mcp_paper_sim_positions')->count());
        config(['mcp.live_exit_signals_enabled' => true, 'paper.watchlist_intake_enabled' => false, 'paper.signals_enabled' => false]);
        app(ExecutionControls::class)->set($c, $c->user_id, (string) Str::uuid(), true, false, 'No more entries.');
        SignalExecutionFixture::quote($c, '70', '70.01');
        $engine = new StrategySignals;
        $exit = $engine->recordLiveExits($c, $c->user_id)->sole();
        $this->assertSame('live', $exit->position_source);
        $this->assertSame('initial_stop_hit', $exit->reason);
        $this->assertSame('SELL', $exit->side);
        $this->assertSame($exit->id, (new StrategySignals)->recordLiveExits($c, $c->user_id)->sole()->id, 'Same-window retry is idempotent.');
        $this->assertEquals($before, DB::table('mcp_paper_portfolios')->where('mcp_connection_id', $c->id)->first());
        $plan = app(SubmissionChecks::class)->plan($c, $exit);
        $this->assertNull($plan['reason'], json_encode($plan));
        $this->assertSame($intent->payload['quantity'], $plan['draft']['quantity']);
        $sell = app(ExecutionJournal::class)->stage($c, $c->user_id, $exit->id, $plan['draft'] + ['expires_at' => now()->addMinute()->toISOString()]);
        $this->assertSame('accepted', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $sell->id));
        $this->assertSame('SELL', $sell->payload['side']);
        config(['paper.signal_handoff_enabled' => true]);
        $this->assertSame(409, $this->refusal(fn () => app(SignalPaperHandoff::class)->process($c, $c->user_id, $exit->id)));
        $this->assertSame(0, DB::table('mcp_paper_sim_orders')->count());
    }

    public function test_paper_sell_signal_cannot_authorize_a_live_exit_and_generation_is_default_off(): void
    {
        [$c] = $this->ready();
        $paper = F::signal($c, 'SELL');
        $this->assertSame('live_exit_requires_live_position_signal', app(SubmissionChecks::class)->plan($c, $paper)['reason']);
        $this->assertSame(409, $this->refusal(fn () => app(ExecutionJournal::class)->stage($c, $c->user_id, $paper->id, [
            'type' => 'market', 'quantity' => '1', 'price_bound' => '70', 'expires_at' => now()->addMinute()->toISOString(),
            'policy_fingerprint' => LivePolicies::current(AccountScope::key($c))->fingerprint])));
        $this->assertSame(409, $this->refusal(fn () => app(StrategySignals::class)->recordLiveExits($c, $c->user_id)));
        $this->artisan('mcp:live-exits', ['--account' => $c->id])->assertFailed();
        $this->assertSame([], $this->gateway->calls);
    }
}
