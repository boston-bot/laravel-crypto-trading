<?php

namespace Tests\Feature\Execution;

use App\Models\Execution\ExecutionPolicy;
use App\Models\McpConnection;
use App\Models\Monitoring\ExternalOrder;
use App\Models\PaperTrading\StrategySignal;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\RobinhoodReadNormalizer;
use App\Services\Execution\BrokerOutcomes;
use App\Services\Execution\ExecutionControls;
use App\Services\Execution\ExecutionJournal;
use App\Services\Execution\LivePolicies;
use App\Services\Execution\SubmissionChecks;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\IncidentRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\LiveBrokerGateway;
use Tests\Support\LiveExecutionFixture as F;
use Tests\Support\SignalExecutionFixture;
use Tests\TestCase;

/** Live checks against synthetic stored broker reads only. Live writes stay disabled; claims are PostgreSQL tests. */
class LiveSubmissionChecksTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY = F::POLICY;

    private LiveBrokerGateway $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    /** @return array{0: McpConnection, 1: StrategySignal, 2: ExecutionPolicy} */
    private function ready(array $policy = []): array
    {
        $this->broker = new LiveBrokerGateway;

        return F::seed($this->broker, $policy);
    }

    private function sync(McpConnection $c): void
    {
        F::sync($c, $this->broker);
    }

    public function test_plan_sizes_from_broker_confirmed_state_and_the_staged_intent_passes_without_touching_paper_or_writes(): void
    {
        [$c, $signal, $policy] = $this->ready();
        $before = DB::table('mcp_paper_portfolios')->where('mcp_connection_id', $c->id)->first();
        $plan = app(SubmissionChecks::class)->plan($c, $signal);
        $this->assertNull($plan['reason'], json_encode($plan));
        $this->assertSame('market', $plan['draft']['type']);
        $this->assertSame($policy->fingerprint, $plan['draft']['policy_fingerprint']);
        $this->assertSame(64, strlen($policy->fingerprint));
        $intent = app(ExecutionJournal::class)->stage($c, $c->user_id, $signal->id, $plan['draft'] + ['expires_at' => now()->addMinutes(4)->toISOString()]);
        $this->assertNull(app(SubmissionChecks::class)->reason($c->fresh(), $intent));
        $this->assertEquals($before, DB::table('mcp_paper_portfolios')->where('mcp_connection_id', $c->id)->first());
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        $this->assertFalse(config('mcp.brokerage_writes_enabled'));
        // 20% of the $1,000 sleeve at the $102.051 collar caps whole-share sizing; risk and cash allow more.
        $this->assertSame(['1', '102.051000', '1', '4', '5'], [$plan['draft']['quantity'], $plan['draft']['price_bound'],
            $plan['evidence']['sizing']['position_quantity'], $plan['evidence']['sizing']['risk_quantity'], $plan['evidence']['sizing']['cash_quantity']]);
    }

    public function test_live_policy_is_explicit_operator_scoped_bounded_versioned_and_immutable(): void
    {
        [$c, , $policy] = $this->ready();
        $policies = app(LivePolicies::class);
        foreach ([['risk_per_trade_pct' => '1.1'], ['max_position_pct' => '21'], ['max_position_pct' => '0'], ['daily_loss_pause_pct' => '11'],
            ['max_open_positions' => 9], ['max_orders_per_session' => 0], ['quote_max_age_seconds' => 61], ['sleeve_capital' => 1000],
            ['sleeve_capital' => '1e3'], ['max_order_notional' => '1000.01'], ['order_type' => 'limit'], ['slippage_bps' => '4.99'],
            ['allow_fractional' => 'yes'], ['order_type' => 'stop', 'allow_fractional' => false], ['require_broker_approval' => '1']] as $patch) {
            $this->refused(fn () => $policies->apply($c, $c->user_id, 1, (string) Str::uuid(), $patch + self::POLICY));
        }
        $this->refused(fn () => $policies->apply($c, $c->user_id, 1, (string) Str::uuid(), array_diff_key(self::POLICY, ['max_order_notional' => 1])));
        $this->refused(fn () => $policies->apply($c, $c->user_id, 1, (string) Str::uuid(), self::POLICY + ['brokerage_writes_enabled' => true]));
        $this->refused(fn () => $policies->apply($c, User::factory()->create()->id, 1, (string) Str::uuid(), self::POLICY), 403);
        $this->refused(fn () => $policies->apply($c, $c->user_id, 0, (string) Str::uuid(), self::POLICY), 409);
        $this->assertSame($policy->id, $policies->apply($c, $c->user_id, 0, $policy->request_id, self::POLICY)->id);
        $this->refused(fn () => $policies->apply($c, $c->user_id, 0, $policy->request_id, ['sleeve_capital' => '900.00'] + self::POLICY), 409);
        $limit = $policies->apply($c, $c->user_id, 1, (string) Str::uuid(), ['order_type' => 'limit', 'allow_fractional' => false] + self::POLICY);
        $this->assertSame(2, $limit->version);
        $this->assertNotSame($policy->fingerprint, $limit->fingerprint);
        $this->assertSame($limit->id, LivePolicies::current(AccountScope::key($c))->id);
        $this->assertSame(LivePolicies::fingerprint(AccountScope::key($c), 2, $limit->payload), $limit->fingerprint);
        $this->assertFalse(config('mcp.brokerage_writes_enabled'));
        $this->expectException(\LogicException::class);
        $limit->update(['version' => 9]);
    }

    public function test_owner_controls_and_policy_changes_deny_a_previously_admissible_intent(): void
    {
        [$c, $signal] = $this->ready();
        $intent = $this->stage($c, $signal);
        $controls = app(ExecutionControls::class);
        $checks = app(SubmissionChecks::class);
        $controls->set($c, $c->user_id, (string) Str::uuid(), true, false, 'pilot review');
        $this->assertSame('buy_paused', $checks->reason($c, $intent));
        $controls->set($c, $c->user_id, (string) Str::uuid(), true, true, 'drill');
        $this->assertSame('execution_emergency_disabled', $checks->reason($c, $intent));
        $this->refused(fn () => $controls->set($c, User::factory()->create()->id, (string) Str::uuid(), false, false, 'not owner'), 403);
        // Engaging works after authorization loss; releasing needs the current authorization.
        $c->update(['auth_generation' => $c->auth_generation + 1]);
        $stale = $c->replicate()->forceFill(['id' => $c->id, 'auth_generation' => $c->auth_generation - 1]);
        $controls->set($stale, $c->user_id, (string) Str::uuid(), true, true, 'still engaged');
        $this->refused(fn () => $controls->set($stale, $c->user_id, (string) Str::uuid(), false, false, 'release'), 409);
        $c->update(['auth_generation' => $c->auth_generation - 1]);
        $controls->set($c, $c->user_id, (string) Str::uuid(), false, false, 'released after drill');
        $this->assertSame(['buy_paused' => false, 'emergency_disabled' => false, 'version' => 4], ExecutionControls::state(AccountScope::key($c)));
        $this->assertNull($checks->reason($c, $intent));
        app(LivePolicies::class)->apply($c, $c->user_id, 1, (string) Str::uuid(), ['max_order_notional' => '200.00'] + self::POLICY);
        $this->assertSame('live_policy_changed', $checks->reason($c, $intent));
    }

    public function test_tampered_size_terms_and_unattributed_sells_are_refused(): void
    {
        [$c, $signal] = $this->ready();
        $checks = app(SubmissionChecks::class);
        $plan = $checks->plan($c, $signal);
        $big = app(ExecutionJournal::class)->stage($c, $c->user_id, $signal->id, ['quantity' => '2'] + $plan['draft'] + ['expires_at' => now()->addMinutes(4)->toISOString()]);
        $this->assertSame('live_size_exceeded', $checks->reason($c, $big));
        $other = $this->signal($c, 'BUY');
        $fraction = app(ExecutionJournal::class)->stage($c, $c->user_id, $other->id, ['quantity' => '0.5'] + $plan['draft'] + ['expires_at' => now()->addMinutes(4)->toISOString()]);
        $this->assertSame('live_size_exceeded', $checks->reason($c, $fraction), 'Fraction without current fractional tradability.');
        $collar = app(ExecutionJournal::class)->stage($c, $c->user_id, $this->signal($c, 'BUY')->id, ['price_bound' => '110'] + $plan['draft'] + ['expires_at' => now()->addMinutes(4)->toISOString()]);
        $this->assertSame('live_order_terms_changed', $checks->reason($c, $collar));
        // The broker holds DEMO, but no broker-confirmed app fill put it there.
        $this->broker->positions['DEMO'] = ['3', '305.00'];
        $this->sync($c);
        $this->assertSame('live_position_unattributed_or_absent', $checks->plan($c, $this->signal($c, 'SELL'))['reason']);
        $this->assertSame('broker_symbol_already_held', $checks->plan($c, $signal)['reason']);
    }

    public function test_stale_or_missing_broker_state_session_and_daily_loss_deny_entries(): void
    {
        [$c, $signal] = $this->ready();
        $checks = app(SubmissionChecks::class);
        $this->travel(31)->seconds();
        $this->assertSame('quote_component_time_unknown_or_stale', $checks->plan($c, $signal)['reason']);
        SignalExecutionFixture::quote($c);
        $this->assertNull($checks->plan($c, $signal)['reason']);
        $this->travel(5)->minutes();
        SignalExecutionFixture::quote($c);
        $this->assertSame('broker_account_unverified', $checks->plan($c, $signal)['reason']);
        // A complete snapshot 2.5% below the 14:00 opening baseline latches the daily loss pause for entries.
        $this->broker->equity = '975.00';
        $this->sync($c);
        $this->assertSame('daily_loss_pause', $checks->plan($c, $signal)['reason']);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T19:56:00Z'));
        $this->assertSame('outside_verified_session', $checks->plan($c, $signal)['reason']);
    }

    public function test_a_manual_buy_that_consumes_broker_buying_power_shrinks_or_denies_the_next_live_entry(): void
    {
        [$c, $signal] = $this->ready();
        $intent = $this->stage($c, $signal);
        // The owner (or another agent) spends cash outside the app; only the broker's figure counts.
        $this->broker->buyingPower = '60.00';
        $this->sync($c);
        SignalExecutionFixture::quote($c);
        $this->assertSame('live_whole_share_size_zero', app(SubmissionChecks::class)->reason($c, $intent));
        $this->assertSame('live_whole_share_size_zero', app(SubmissionChecks::class)->plan($c, $signal)['reason']);
    }

    public function test_every_live_order_needs_a_fresh_broker_approval_setting_matching_the_owner_policy(): void
    {
        [$c, $signal] = $this->ready();
        $checks = app(SubmissionChecks::class);
        $this->assertNull($checks->plan($c, $signal)['reason']);
        $this->broker->approvalRequired = false; // The owner turned approvals off in the Robinhood app.
        $this->sync($c);
        SignalExecutionFixture::quote($c);
        $this->assertSame('broker_approval_setting_differs', $checks->plan($c, $signal)['reason']);
        $this->assertSame('broker_approval_setting_differs', $checks->plan($c, $this->signal($c, 'SELL'))['reason'], 'Exits run under the same approval mode.');
        $this->broker->approvalRequired = null; // The optional read fails; monitoring still completes.
        $this->sync($c);
        $this->assertSame('complete', DB::table('mcp_snapshots')->latest('id')->value('status'));
        $this->assertSame('broker_approval_setting_unverified', $checks->plan($c, $signal)['reason']);
        config(['robinhood_mcp.approval_setting_enabled' => false]);
        $this->broker->approvalRequired = true;
        $this->sync($c);
        $this->assertSame('broker_approval_setting_unverified', $checks->plan($c, $signal)['reason']);
        app(LivePolicies::class)->apply($c, $c->user_id, 1, (string) Str::uuid(), ['require_broker_approval' => false] + self::POLICY);
        config(['robinhood_mcp.approval_setting_enabled' => true]);
        $this->broker->approvalRequired = false;
        $this->sync($c);
        $this->assertNull($checks->plan($c, $signal)['reason']);
    }

    public function test_approval_setting_reads_must_name_the_bound_account_with_a_boolean(): void
    {
        $fields = RobinhoodReadNormalizer::fields(Operation::TradeApprovalSetting, ['account_number' => 'ACCT1', 'human_must_approve_trades' => true, 'updated_at' => '2026-10-25T00:00:00Z'], 'ACCT1');
        $this->assertTrue(BrokerRecord::from(Operation::TradeApprovalSetting, $fields, null)->fields['human_must_approve_trades']);
        foreach ([['account_number' => 'OTHER', 'human_must_approve_trades' => true], ['account_number' => 'ACCT1', 'human_must_approve_trades' => 'true'], ['account_number' => 'ACCT1']] as $row) {
            try {
                BrokerRecord::from(Operation::TradeApprovalSetting, RobinhoodReadNormalizer::fields(Operation::TradeApprovalSetting, $row, 'ACCT1'), null);
                $this->fail('Malformed approval setting accepted.');
            } catch (ReadFailure $failure) {
                $this->assertSame(ReadError::Malformed, $failure->reason);
            }
        }
    }

    public function test_entries_need_a_broker_confirmed_opening_baseline(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:01:00Z'));
        [$c, $signal] = $this->ready();
        $this->assertSame('live_session_baseline_unavailable', app(SubmissionChecks::class)->plan($c, $signal)['reason']);
    }

    public function test_external_orders_detection_and_reconciliation_incidents_block_entries(): void
    {
        [$c, $signal] = $this->ready();
        $checks = app(SubmissionChecks::class);
        config(['mcp.external_order_detection_enabled' => false]);
        $this->assertSame('external_order_detection_disabled', $checks->plan($c, $signal)['reason']);
        config(['mcp.external_order_detection_enabled' => true]);
        $this->broker->orders = [LiveBrokerGateway::order('not-the-app', 'filled', '1', '1', [['exec-x', '1', '100.00']])];
        $this->sync($c);
        SignalExecutionFixture::quote($c);
        $this->assertSame(1, ExternalOrder::count());
        $this->assertSame('external_orders_review_required', $checks->plan($c, $signal)['reason']);
        app(IncidentRecorder::class)->record($c, BrokerOutcomes::MISMATCH, 'critical', 'execution');
        $this->assertSame('execution_reconciliation_review_required', $checks->plan($c, $signal)['reason']);
        $this->refused(fn () => app(BrokerOutcomes::class)->review($c, User::factory()->create()->id, BrokerOutcomes::MISMATCH), 403);
        app(BrokerOutcomes::class)->review($c, $c->user_id, BrokerOutcomes::MISMATCH);
        $this->assertSame('external_orders_review_required', $checks->plan($c, $signal)['reason']);
    }

    private function stage(McpConnection $c, StrategySignal $signal)
    {
        return F::stage($c, $signal);
    }

    private function signal(McpConnection $c, string $side): StrategySignal
    {
        return F::signal($c, $side);
    }

    private function refused(callable $work, ?int $status = null): void
    {
        try {
            $work();
            $this->fail('Expected refusal.');
        } catch (HttpException $e) {
            $this->assertSame($status ?? $e->getStatusCode(), $e->getStatusCode(), $e->getMessage());
            $this->assertTrue($status !== null || in_array($e->getStatusCode(), [409, 422], true));
        } catch (ValidationException) {
            $this->assertNull($status);
        }
    }
}
