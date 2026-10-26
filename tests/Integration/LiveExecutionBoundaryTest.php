<?php

namespace Tests\Integration;

use App\Models\Execution\BrokerOrderBinding;
use App\Models\Execution\ExecutionFill;
use App\Models\Execution\ExecutionOutcome;
use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Models\Monitoring\ExternalOrder;
use App\Models\Monitoring\ExternalOrderReview;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Snapshot;
use App\Services\Execution\BrokerOutcomes;
use App\Services\Execution\ExecutionControls;
use App\Services\Execution\ExecutionJournal;
use App\Services\Execution\SubmissionChecks;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\ExternalOrders;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\ExecutionJournalFixture;
use Tests\Support\LiveBrokerGateway;
use Tests\Support\LiveExecutionFixture as F;
use Tests\Support\SignalExecutionFixture;
use Tests\TestCase;

/**
 * The live boundary with real claims, synthetic broker reads and fake order-history states. No broker write gateway
 * exists; "sending" is represented only by the journal calls a future dispatcher would make.
 */
class LiveExecutionBoundaryTest extends TestCase
{
    private ?int $fixture = null;

    private LiveBrokerGateway $broker;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
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
        config(['mcp.brokerage_writes_enabled' => true]); // Test-only: there is no write gateway to reach.

        return [$c, $signal, app(ExecutionJournal::class)];
    }

    private function report(McpConnection $c, array $orders): void
    {
        $this->travel(1)->seconds();
        $this->broker->orders = $orders;
        F::sync($c, $this->broker);
    }

    private function dispatchStatus(OrderIntent $intent): string
    {
        return DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->value('status');
    }

    public function test_accepted_order_reconciles_through_partial_fill_to_filled_with_deduplicated_broker_fills(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $before = DB::table('mcp_paper_portfolios')->where('mcp_connection_id', $c->id)->first();
        $intent = F::stage($c, $signal);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $this->assertNotNull($token);
        $this->assertTrue($journal->beforeSend($c, $c->user_id, $intent->id, $token));
        $journal->accepted($c, $c->user_id, $intent->id, $token, 'rh-order-1');
        $journal->accepted($c, $c->user_id, $intent->id, $token, 'rh-order-1');
        $this->refused(fn () => $journal->accepted($c, $c->user_id, $intent->id, $token, 'rh-order-2'));
        $this->assertSame('rh-order-1', BrokerOrderBinding::where('intent_id', $intent->id)->sole()->broker_order_id);
        $this->assertStringNotContainsString('rh-order-1', (string) DB::table('mcp_execution_broker_orders')->value('broker_order_id'));

        $this->report($c, [LiveBrokerGateway::order('rh-order-1', 'confirmed', '1')]);
        $this->assertSame('accepted', $this->dispatchStatus($intent));
        $this->assertSame(0, ExternalOrder::where('mcp_connection_id', $c->id)->count(), 'The app-bound order is not external.');
        // An open app order blocks every other claim on the account before checks run.
        $this->assertNull($journal->claim($c, $c->user_id, F::stage($c, F::signal($c, 'BUY'))->id));

        $partial = [LiveBrokerGateway::order('rh-order-1', 'partially_filled', '1', '0.4', [['exec-1', '0.4', '101.70']])];
        $this->report($c, $partial);
        $this->report($c, $partial); // Duplicate delivery.
        $this->assertSame('partially_filled', $this->dispatchStatus($intent));
        $this->assertSame(2, ExecutionOutcome::where('intent_id', $intent->id)->count());
        $this->assertSame(1, ExecutionFill::where('intent_id', $intent->id)->count());

        $this->report($c, [LiveBrokerGateway::order('rh-order-1', 'filled', '1', '1', [['exec-1', '0.4', '101.70'], ['exec-2', '0.6', '101.70']])]);
        $this->assertSame('filled', $this->dispatchStatus($intent));
        $this->assertSame(['0.400000000000000000', '0.600000000000000000'], ExecutionFill::where('intent_id', $intent->id)->orderBy('id')->pluck('quantity')->all());
        $this->assertSame(['staged', 'submitting', 'accepted', 'partially_filled', 'filled'],
            DB::table('mcp_execution_events')->where('intent_id', $intent->id)->orderBy('version')->pluck('status')->all());
        $this->assertSame(0, app(SubmissionChecks::class)->attributed(AccountScope::key($c), 'DEMO')->compareTo(Decimal::from('1')));
        $this->assertEquals($before, DB::table('mcp_paper_portfolios')->where('mcp_connection_id', $c->id)->first());
        $this->assertSame(0, DB::table('mcp_paper_sim_orders')->count());

        // Exits sell only broker-held shares the app's confirmed fills put there.
        $this->broker->positions['DEMO'] = ['1.5', '152.55'];
        $this->report($c, $this->broker->orders);
        F::sync($c, $this->broker);
        SignalExecutionFixture::quote($c);
        $sell = app(SubmissionChecks::class)->plan($c, F::signal($c, 'SELL'));
        $this->assertNull($sell['reason'], json_encode($sell));
        $this->assertSame('1', $sell['draft']['quantity']);
    }

    public function test_emergency_disable_during_queued_work_withdraws_before_send_and_denies_new_claims(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $queued = F::stage($c, F::signal($c, 'BUY'));
        $token = $journal->claim($c, $c->user_id, $intent->id);
        app(ExecutionControls::class)->set($c, $c->user_id, (string) Str::uuid(), true, true, 'emergency drill');
        $this->assertFalse($journal->beforeSend($c, $c->user_id, $intent->id, $token));
        $this->assertSame('withdrawn', $this->dispatchStatus($intent));
        $this->assertSame('withdrawn_emergency_disabled', DB::table('mcp_execution_events')->where('intent_id', $intent->id)->where('version', 3)->value('reason'));
        $this->refused(fn () => $journal->accepted($c, $c->user_id, $intent->id, $token, 'late-response'));
        $this->refused(fn () => $journal->beforeSend($c, $c->user_id, $intent->id, $token));
        try {
            $journal->claim($c, $c->user_id, $queued->id);
            $this->fail('Emergency disable must deny queued intents.');
        } catch (HttpException $error) {
            $this->assertSame('execution_emergency_disabled', $error->getMessage());
        }
        $this->assertSame('staged', $this->dispatchStatus($queued));
        // Disabling the write flag after a claim also withdraws without sending.
        app(ExecutionControls::class)->set($c, $c->user_id, (string) Str::uuid(), false, false, 'drill complete');
        $second = $journal->claim($c, $c->user_id, $queued->id);
        config(['mcp.brokerage_writes_enabled' => false]);
        $this->assertFalse($journal->beforeSend($c, $c->user_id, $queued->id, $second));
        $this->assertSame('withdrawn_writes_disabled', DB::table('mcp_execution_events')->where('intent_id', $queued->id)->where('version', 3)->value('reason'));
        $this->assertSame(0, DB::table('mcp_execution_broker_orders')->count());
    }

    public function test_lost_response_is_external_until_the_owner_links_the_matching_broker_order(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $journal->uncertain($c, $c->user_id, $intent->id, $token); // Accepted by the broker, response lost.
        $this->report($c, [LiveBrokerGateway::order('rh-lost', 'filled', '1', '1', [['exec-lost', '1', '101.70']]),
            LiveBrokerGateway::order('rh-other', 'filled', '2', '2', [['exec-other', '2', '50.00']], 'OTHER')]);
        $this->assertSame(2, ExternalOrder::where('mcp_connection_id', $c->id)->count());
        $this->assertSame(ExternalOrders::INCIDENT, app(ExternalOrders::class)->entryReason($c));
        $this->travel(7)->minutes();
        $this->report($c, $this->broker->orders);
        $this->refused(fn () => $journal->absent($c, $c->user_id, $intent->id), 409, 'Broker reports a matching order; link it instead.');
        $lost = ExternalOrder::where('mcp_connection_id', $c->id)->where('broker_id_hash', hash('sha256', 'rh-lost'))->sole();
        $other = ExternalOrder::where('mcp_connection_id', $c->id)->where('broker_id_hash', hash('sha256', 'rh-other'))->sole();
        $this->refused(fn () => $journal->link($c, $c->user_id, $intent->id, $other->id, (string) Str::uuid(), 'wrong order'), 409);
        $request = (string) Str::uuid();
        $journal->link($c, $c->user_id, $intent->id, $lost->id, $request, 'Lost acknowledgement; broker order matches the intent.');
        $journal->link($c, $c->user_id, $intent->id, $lost->id, $request, 'Lost acknowledgement; broker order matches the intent.');
        $this->assertSame('accepted', $this->dispatchStatus($intent));
        $this->assertTrue(ExternalOrderReview::where('external_order_id', $lost->id)->exists());
        $this->assertSame('open', Incident::where('mcp_connection_id', $c->id)->where('incident_key', ExternalOrders::INCIDENT)->value('state'), 'The unrelated order still needs review.');
        $this->report($c, $this->broker->orders);
        $this->assertSame('filled', $this->dispatchStatus($intent));
        $this->assertSame(1, ExecutionFill::where('intent_id', $intent->id)->count());
        $this->assertSame(2, ExternalOrder::where('mcp_connection_id', $c->id)->count());
    }

    public function test_unknown_outcome_is_confirmed_absent_only_from_complete_history_after_the_deadline(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $journal->uncertain($c, $c->user_id, $intent->id, $token); // Crash before or after the request: unknown.
        $this->refused(fn () => $journal->absent($c, $c->user_id, $intent->id), 409, 'A complete order history read after the deadline is required.');
        $this->assertNull($journal->claim($c, $c->user_id, F::stage($c, F::signal($c, 'BUY'))->id));
        $this->travel(7)->minutes();
        $this->report($c, []);
        $journal->absent($c, $c->user_id, $intent->id);
        $journal->absent($c, $c->user_id, $intent->id);
        $this->assertSame('absent', $this->dispatchStatus($intent));
        $this->assertSame('owner_confirmed_no_broker_order', DB::table('mcp_execution_events')->where('intent_id', $intent->id)->where('status', 'absent')->value('reason'));
        $this->assertSame(0, DB::table('mcp_execution_dispatches as d')->join('mcp_execution_intents as i', 'i.id', '=', 'd.intent_id')
            ->where('i.mcp_connection_id', $c->id)->whereIn('d.status', ExecutionJournal::BLOCKING)->count());
    }

    public function test_partial_cancel_rejection_and_contradictions_are_recorded_or_raise_a_blocking_incident(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $journal->accepted($c, $c->user_id, $intent->id, $token, 'rh-partial');
        $this->report($c, [LiveBrokerGateway::order('rh-partial', 'partially_filled', '1', '0.4', [['exec-p', '0.4', '101.70']])]);
        $this->report($c, [LiveBrokerGateway::order('rh-partial', 'cancelled', '1', '0.4', [['exec-p', '0.4', '101.70']])]);
        $this->assertSame('broker_cancelled', $this->dispatchStatus($intent));
        $this->assertSame(0, app(SubmissionChecks::class)->attributed(AccountScope::key($c), 'DEMO')->compareTo(Decimal::from('0.4')));
        $outcomes = ExecutionOutcome::where('intent_id', $intent->id)->count();
        $next = F::stage($c, F::signal($c, 'BUY'));
        // The broker later contradicts a terminal outcome: nothing changes and every live claim is blocked.
        $this->report($c, [LiveBrokerGateway::order('rh-partial', 'filled', '1', '1', [['exec-p', '0.4', '101.70'], ['exec-q', '0.6', '101.70']])]);
        $this->assertSame('broker_cancelled', $this->dispatchStatus($intent));
        $this->assertSame($outcomes, ExecutionOutcome::where('intent_id', $intent->id)->count());
        $this->assertSame(1, ExecutionFill::where('intent_id', $intent->id)->count());
        $this->assertSame('open', Incident::where('mcp_connection_id', $c->id)->where('incident_key', BrokerOutcomes::MISMATCH)->value('state'));
        $this->refused(fn () => $journal->claim($c, $c->user_id, $next->id), 409, 'execution_reconciliation_review_required');
        app(BrokerOutcomes::class)->review($c, $c->user_id, BrokerOutcomes::MISMATCH);
        $this->report($c, []);
        SignalExecutionFixture::quote($c);
        $retry = $journal->claim($c, $c->user_id, $next->id);
        $this->assertNotNull($retry);
        $journal->accepted($c, $c->user_id, $next->id, $retry, 'rh-rejected');
        // A changed quantity is a contradiction, not a rejection.
        $this->report($c, [LiveBrokerGateway::order('rh-rejected', 'rejected', '3')]);
        $this->assertSame('accepted', $this->dispatchStatus($next));
        $this->assertSame('open', Incident::where('mcp_connection_id', $c->id)->where('incident_key', BrokerOutcomes::MISMATCH)->value('state'));
        $this->report($c, [LiveBrokerGateway::order('rh-rejected', 'rejected', '1')]);
        $this->assertSame('rejected', $this->dispatchStatus($next));
        $this->assertSame(0, ExecutionFill::where('intent_id', $next->id)->count());
        // Unknown broker states are never guessed; a contradiction that persists reopens after review.
        app(BrokerOutcomes::class)->review($c, $c->user_id, BrokerOutcomes::MISMATCH);
        $this->assertSame('resolved', Incident::where('mcp_connection_id', $c->id)->where('incident_key', BrokerOutcomes::MISMATCH)->value('state'));
        $this->report($c, [LiveBrokerGateway::order('rh-rejected', 'held_for_review', '1')]);
        $this->assertSame('rejected', $this->dispatchStatus($next));
        $this->assertSame('open', Incident::where('mcp_connection_id', $c->id)->where('incident_key', BrokerOutcomes::MISMATCH)->value('state'));
    }

    public function test_session_order_cap_counts_every_claim_on_the_account(): void
    {
        $this->broker = new LiveBrokerGateway;
        [$c, $signal] = F::seed($this->broker, ['max_orders_per_session' => 1]);
        $this->fixture = $c->id;
        config(['mcp.brokerage_writes_enabled' => true]);
        $journal = app(ExecutionJournal::class);
        $intent = F::stage($c, $signal);
        $next = F::stage($c, F::signal($c, 'BUY'));
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $this->assertTrue($journal->beforeSend($c, $c->user_id, $intent->id, $token));
        $journal->accepted($c, $c->user_id, $intent->id, $token, 'rh-cap');
        $this->report($c, [LiveBrokerGateway::order('rh-cap', 'cancelled', '1')]);
        $this->assertSame('broker_cancelled', $this->dispatchStatus($intent));
        SignalExecutionFixture::quote($c);
        $this->refused(fn () => $journal->claim($c, $c->user_id, $next->id), 409, 'live_session_order_limit');
    }

    public function test_ref_id_proves_the_app_order_after_a_lost_response_without_an_owner_link(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $journal->uncertain($c, $c->user_id, $intent->id, $token);
        $ref = strtoupper($intent->idempotency_key); // The broker echoes the key; case is not significant.
        $this->report($c, [LiveBrokerGateway::order('rh-by-ref', 'filled', '1', '1', [['exec-ref', '1', '101.70']], ref: $ref),
            LiveBrokerGateway::order('rh-chatgpt', 'filled', '1', '1', [['exec-gpt', '1', '101.70']])]);
        $this->assertSame('filled', $this->dispatchStatus($intent));
        $this->assertSame('ref_id_match', BrokerOrderBinding::where('intent_id', $intent->id)->value('source'));
        $this->assertSame(['staged', 'submitting', 'unknown', 'accepted', 'filled'], DB::table('mcp_execution_events')->where('intent_id', $intent->id)->orderBy('version')->pluck('status')->all());
        // Only the other agent's identical-looking order is external.
        $this->assertSame([hash('sha256', 'rh-chatgpt')], ExternalOrder::where('mcp_connection_id', $c->id)->pluck('broker_id_hash')->all());
        $this->assertSame(1, ExecutionFill::where('intent_id', $intent->id)->count());
    }

    public function test_ref_id_binding_during_submission_is_idempotent_with_the_acknowledgement(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $this->report($c, [LiveBrokerGateway::order('rh-race-ack', 'confirmed', '1', ref: $intent->idempotency_key)]);
        $this->assertSame('accepted', $this->dispatchStatus($intent));
        $journal->accepted($c, $c->user_id, $intent->id, $token, 'rh-race-ack');
        $this->refused(fn () => $journal->accepted($c, $c->user_id, $intent->id, $token, 'rh-something-else'));
        $this->assertSame(1, BrokerOrderBinding::where('intent_id', $intent->id)->count());
    }

    public function test_a_ref_id_order_that_differs_from_the_intent_stays_external_and_blocks(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $journal->approvalPending($c, $c->user_id, $intent->id, $token, 'approval-edited', 'pending', false, now()->addMinutes(10)->toIso8601String());
        // The owner edited the quantity before approving: that order is the owner's, not the app's.
        $this->report($c, [LiveBrokerGateway::order('rh-edited', 'filled', '3', '3', [['exec-edit', '3', '101.70']], ref: $intent->idempotency_key)]);
        $this->assertSame('approval_pending', $this->dispatchStatus($intent));
        $this->assertSame(0, BrokerOrderBinding::where('intent_id', $intent->id)->count());
        $this->assertSame(1, ExternalOrder::where('mcp_connection_id', $c->id)->count());
        $this->assertSame('open', Incident::where('mcp_connection_id', $c->id)->where('incident_key', BrokerOutcomes::MISMATCH)->value('state'));
        $this->travel(13)->minutes();
        $this->report($c, $this->broker->orders);
        $this->refused(fn () => $journal->absent($c, $c->user_id, $intent->id), 409, 'Broker reports a matching order; link it instead.');
    }

    public function test_customer_approval_blocks_the_account_until_the_approved_order_appears_by_ref_id(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $next = F::stage($c, F::signal($c, 'BUY'));
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $journal->approvalPending($c, $c->user_id, $intent->id, $token, 'approval-1', 'pending', false, now()->addMinutes(30)->toIso8601String());
        $journal->approvalPending($c, $c->user_id, $intent->id, $token, 'approval-1', 'pending', false, now()->addMinutes(30)->toIso8601String());
        $this->refused(fn () => $journal->approvalPending($c, $c->user_id, $intent->id, $token, 'approval-2', 'pending', false, now()->addMinutes(30)->toIso8601String()));
        $this->assertSame('approval_pending', $this->dispatchStatus($intent));
        $this->assertStringNotContainsString('approval-1', (string) DB::table('mcp_execution_approvals')->value('approval_id'));
        $this->assertNull($journal->claim($c, $c->user_id, $next->id), 'A pending approval blocks every other claim.');
        $this->report($c, []);
        $this->assertSame('approval_pending', $this->dispatchStatus($intent), 'No order yet is not absence.');
        $this->assertNull(Incident::where('mcp_connection_id', $c->id)->where('incident_key', BrokerOutcomes::MISSING)->value('state'));
        // The owner approves in the Robinhood app; the order the broker places carries the app's ref_id.
        $this->report($c, [LiveBrokerGateway::order('rh-approved', 'new', '1', ref: $intent->idempotency_key)]);
        $this->assertSame('accepted', $this->dispatchStatus($intent));
        $this->report($c, [LiveBrokerGateway::order('rh-approved', 'filled', '1', '1', [['exec-approved', '1', '101.70']], ref: $intent->idempotency_key)]);
        $this->assertSame('filled', $this->dispatchStatus($intent));
        $this->assertSame(0, ExternalOrder::where('mcp_connection_id', $c->id)->count());
    }

    public function test_declined_or_expired_approval_is_confirmed_absent_only_after_the_approval_expiry(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $journal->approvalPending($c, $c->user_id, $intent->id, $token, 'approval-declined', 'pending', false, now()->addMinutes(20)->toIso8601String());
        $this->travel(7)->minutes(); // Past the four-minute submission deadline, but not the approval expiry.
        $this->report($c, []);
        $this->refused(fn () => $journal->absent($c, $c->user_id, $intent->id), 409, 'A complete order history read after the deadline is required.');
        $this->travel(16)->minutes();
        $this->report($c, []);
        $journal->absent($c, $c->user_id, $intent->id);
        $this->assertSame('absent', $this->dispatchStatus($intent));
    }

    public function test_documented_new_and_voided_states_reconcile_without_incidents(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $journal->accepted($c, $c->user_id, $intent->id, $token, 'rh-voided');
        $this->report($c, [LiveBrokerGateway::order('rh-voided', 'new', '1')]);
        $this->report($c, [LiveBrokerGateway::order('rh-voided', 'voided', '1')]);
        $this->assertSame('broker_cancelled', $this->dispatchStatus($intent));
        $this->assertSame(0, Incident::where('mcp_connection_id', $c->id)->whereIn('incident_key', [BrokerOutcomes::MISMATCH, BrokerOutcomes::MISSING])->count());
        $this->sqlRefused(fn () => DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->update(['status' => 'approval_pending', 'version' => 5]));
    }

    public function test_complete_history_missing_an_open_app_order_raises_an_incident(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $journal->accepted($c, $c->user_id, $intent->id, $token, 'rh-vanished');
        $this->report($c, []);
        $this->assertSame('accepted', $this->dispatchStatus($intent));
        $this->assertSame('open', Incident::where('mcp_connection_id', $c->id)->where('incident_key', BrokerOutcomes::MISSING)->value('state'));
        $this->report($c, [array_replace(LiveBrokerGateway::order('rh-vanished', 'queued', '1'), ['executions' => null])]);
        $this->assertSame(1, ExecutionOutcome::where('intent_id', $intent->id)->count());
    }

    public function test_duplicate_concurrent_order_reads_record_one_outcome_and_one_fill(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $journal->accepted($c, $c->user_id, $intent->id, $token, 'rh-race');
        $snapshot = Snapshot::where('mcp_connection_id', $c->id)->latest('id')->firstOrFail();
        $orders = json_encode([LiveBrokerGateway::order('rh-race', 'filled', '1', '1', [['exec-race', '1', '101.70']])]);
        $workers = [];
        $claims = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        DB::beginTransaction();
        DB::table('mcp_connections')->where('id', $c->id)->lockForUpdate()->first();
        try {
            foreach ($claims as $claim) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-execution-outcome-worker.php'), (string) $c->id, (string) $snapshot->id,
                    $claim, now()->toISOString(), $orders], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }
            DB::commit();
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $this->assertSame(3, DB::table('mcp_broker_order_reads')->whereIn('read_claim', $claims)->count());
        $this->assertSame('filled', $this->dispatchStatus($intent));
        $this->assertSame(1, ExecutionOutcome::where('intent_id', $intent->id)->count());
        $this->assertSame(1, ExecutionFill::where('intent_id', $intent->id)->count());
        $this->assertSame(0, ExternalOrder::where('mcp_connection_id', $c->id)->count());
    }

    public function test_database_refuses_outcome_edits_and_lifecycle_shortcuts(): void
    {
        [$c, $signal, $journal] = $this->ready();
        $intent = F::stage($c, $signal);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $this->sqlRefused(fn () => DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->update(['status' => 'filled', 'version' => 3]));
        $this->sqlRefused(fn () => DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->update(['status' => 'absent', 'version' => 3]));
        $journal->accepted($c, $c->user_id, $intent->id, $token, 'rh-sql');
        $this->report($c, [LiveBrokerGateway::order('rh-sql', 'filled', '1', '1', [['exec-sql', '1', '101.70']])]);
        $this->sqlRefused(fn () => DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->update(['status' => 'accepted', 'version' => 5]));
        $this->sqlRefused(fn () => DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->update(['status' => 'partially_filled', 'version' => 5]));
        $this->sqlRefused(fn () => DB::table('mcp_execution_outcomes')->where('intent_id', $intent->id)->update(['filled_quantity' => '0']));
        $this->sqlRefused(fn () => DB::table('mcp_execution_fills')->where('intent_id', $intent->id)->delete());
        $this->sqlRefused(fn () => DB::table('mcp_execution_broker_orders')->where('intent_id', $intent->id)->update(['broker_id_hash' => str_repeat('0', 64)]));
        $this->sqlRefused(fn () => DB::table('mcp_execution_policies')->update(['payload' => '{}']));
        $this->sqlRefused(fn () => DB::table('mcp_execution_fills')->insert(['intent_id' => $intent->id, 'outcome_id' => ExecutionOutcome::where('intent_id', $intent->id)->value('id'),
            'execution_account_id' => $intent->execution_account_id, 'execution_id_hash' => str_repeat('1', 64), 'symbol' => 'DEMO', 'side' => 'BUY',
            'quantity' => '-1', 'price' => '1', 'fees' => '0', 'executed_at' => now(), 'recorded_at' => now()]));
        $this->assertSame('filled', $this->dispatchStatus($intent));
    }

    private function sqlRefused(callable $work): void
    {
        try {
            DB::transaction($work);
            $this->fail('Expected database constraint/trigger refusal.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    private function refused(callable $work, int $status = 409, ?string $message = null): void
    {
        try {
            $work();
            $this->fail('Expected refusal.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode(), $e->getMessage());
            $message === null || $this->assertSame($message, $e->getMessage());
        }
    }
}
