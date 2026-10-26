<?php

namespace App\Services\Execution;

use App\Models\Execution\BrokerOrderBinding;
use App\Models\Execution\ExecutionApproval;
use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Models\Monitoring\BrokerOrderRead;
use App\Models\Monitoring\ExternalOrder;
use App\Models\Monitoring\ExternalOrderReview;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Models\User;
use App\Services\Broker\Agentic\RobinhoodOrderContract;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\ExternalOrders;
use App\Services\Monitoring\IncidentRecorder;
use App\Services\PaperTrading\SignalRules;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use Ramsey\Uuid\Uuid;

/** Durable journal/outbox and broker-confirmed outcome binding. No network, scheduler or broker write gateway. */
final class ExecutionJournal
{
    /** Claimed states whose broker outcome is unresolved or still open. */
    public const BLOCKING = ['submitting', 'unknown', 'approval_pending', 'accepted', 'partially_filled'];

    /** Claimed states with no proven broker order yet; ref_id proof, an owner link or confirmed absence resolves them. */
    public const UNBOUND = ['submitting', 'unknown', 'approval_pending'];

    public function __construct(private SubmissionChecks $checks) {}

    /**
     * Record an unapproved draft from the shared deterministic engine. Quantities and the policy fingerprint
     * are evidence to revalidate at the future live boundary, never proof of buying power or live permission.
     */
    public function stage(McpConnection $connection, int $actor, int $signalId, array $draft): OrderIntent
    {
        $draft = $this->draft($draft);

        return DB::transaction(function () use ($connection, $actor, $signalId, $draft) {
            $current = $this->connection($connection, $actor);
            $scope = AccountScope::key($current);
            DB::table('mcp_execution_accounts')->insertOrIgnore(['scope' => $scope]);
            $account = DB::table('mcp_execution_accounts')->where('scope', $scope)->lockForUpdate()->first();
            $signal = StrategySignal::whereKey($signalId)->where('mcp_connection_id', $current->id)->firstOrFail();
            $cycle = StrategyCycle::findOrFail($signal->cycle_id);
            abort_unless($signal->side !== 'SELL' || ($signal->position_source === 'live' && $cycle->position_source === 'live'), 409, 'Live exits require live position evidence.');
            abort_unless($signal->status === 'signal' && in_array($signal->side, ['BUY', 'SELL'], true) &&
                $signal->asset_class === 'equity' && $signal->venue === 'robinhood_agentic' && $signal->rule_version === SignalRules::VERSION &&
                $cycle->mcp_connection_id === $current->id && $cycle->scope === $scope && $cycle->auth_generation === $current->auth_generation, 409, 'Signal provenance is invalid.');
            $payload = ['contract' => 'execution_intent_v1', 'mode' => 'live_intent_unapproved',
                'symbol' => $signal->symbol, 'side' => $signal->side, 'signal_id' => $signal->id, 'position_source' => $signal->position_source,
                'signal_input_fingerprint' => $signal->input_fingerprint, 'rule_version' => $signal->rule_version,
                'parameters_version' => $signal->parameters_version, 'profile_id' => $signal->profile_id,
                'watchlist_revision_id' => $signal->watchlist_revision_id, 'market_input_id' => $signal->market_input_id] + $draft;
            $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            if ($prior = OrderIntent::where('execution_account_id', $account->id)->where('signal_id', $signalId)->first()) {
                abort_unless($prior->fingerprint === $fingerprint && $prior->auth_generation === $current->auth_generation &&
                    $prior->mcp_connection_id === $current->id && $prior->actor_id === $actor, 409, 'Logical order already has a different intent.');

                return $prior;
            }
            abort_unless(! $signal->cutoff->isFuture() && $signal->cutoff->gt(CarbonImmutable::now()->subMinutes(5)) &&
                ! $signal->recorded_at->isFuture() && CarbonImmutable::parse($draft['expires_at'])->isFuture() &&
                CarbonImmutable::parse($draft['expires_at'])->lte($signal->cutoff->addMinutes(5)), 409, 'Intent or signal is expired or exceeds its submission window.');
            $intent = OrderIntent::create(['execution_account_id' => $account->id, 'mcp_connection_id' => $current->id,
                'actor_id' => $actor, 'signal_id' => $signalId, 'auth_generation' => $current->auth_generation,
                'idempotency_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'live-equity:'.$scope.':signal:'.$signalId)->toString(),
                'fingerprint' => $fingerprint, 'payload' => $payload, 'recorded_at' => now(), 'expires_at' => $draft['expires_at']]);
            DB::table('mcp_execution_dispatches')->insert(['intent_id' => $intent->id, 'status' => 'staged', 'version' => 1, 'updated_at' => now()]);
            $this->event($intent->id, 1, 'staged', 'unapproved_intent_recorded');

            return $intent;
        });
    }

    /** Claim must commit BEFORE any future external call. There is deliberately no lease expiry or replay. */
    public function claim(McpConnection $connection, int $actor, int $intentId): ?string
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Submission ownership must commit independently before any external request.');
        }
        abort_unless(DB::connection()->getDriverName() === 'pgsql', 409, 'Live ownership requires PostgreSQL.');

        return DB::transaction(function () use ($connection, $actor, $intentId) {
            $current = $this->connection($connection, $actor);
            $intent = $this->intent($current, $intentId);
            DB::table('mcp_execution_accounts')->where('id', $intent->execution_account_id)->lockForUpdate()->firstOrFail();
            $dispatch = DB::table('mcp_execution_dispatches')->where('intent_id', $intentId)->lockForUpdate()->firstOrFail();
            if ($dispatch->status !== 'staged') {
                return null;
            }
            if ($intent->expires_at->lte(CarbonImmutable::now())) {
                $this->transition($dispatch, 'expired', 'intent_expired');

                return null;
            }
            // Includes the same account through a different connection or authorization generation. An open broker
            // order also blocks: one live order per account keeps buying power and position checks exact.
            $unresolved = DB::table('mcp_execution_dispatches as d')->join('mcp_execution_intents as i', 'i.id', '=', 'd.intent_id')
                ->where('i.execution_account_id', $intent->execution_account_id)->whereIn('d.status', self::BLOCKING)->exists();
            if ($unresolved) {
                return null;
            }
            abort_unless(config('mcp.brokerage_writes_enabled'), 409, 'Brokerage writes are disabled.');
            abort_if($reason = $this->checks->reason($current, $intent), 409, $reason ?? 'Submission denied.');
            $token = (string) Str::uuid();
            app(ExecutionRecoveryFence::class)->reserve($intent, AccountScope::key($current), $token);
            $this->transition($dispatch, 'submitting', 'submission_claimed', ['claim_token' => $token, 'claimed_at' => now()]);

            return $token;
        });
    }

    /** A crash/timeout on either side of the external call cannot establish whether an order exists. */
    public function uncertain(McpConnection $connection, int $actor, int $intentId, string $token): void
    {
        DB::transaction(function () use ($connection, $actor, $intentId, $token) {
            // Old authorizations must still be recoverable; they can only become more restrictive here.
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->operator($current, $actor);
            $intent = OrderIntent::whereKey($intentId)->where('mcp_connection_id', $current->id)->firstOrFail();
            DB::table('mcp_execution_accounts')->where('id', $intent->execution_account_id)->lockForUpdate()->firstOrFail();
            $dispatch = DB::table('mcp_execution_dispatches')->where('intent_id', $intentId)->lockForUpdate()->firstOrFail();
            abort_unless($dispatch->claim_token !== null && hash_equals($dispatch->claim_token, $token) &&
                in_array($dispatch->status, ['submitting', 'unknown'], true), 409, 'Submission ownership changed.');
            if ($dispatch->status === 'submitting') {
                $this->transition($dispatch, 'unknown', 'submission_outcome_unknown');
            }
        });
    }

    /**
     * The claim owner's last local check after broker preview. Re-run every submission check using current stored
     * facts under the connection/account locks. Nothing has been sent; a denial withdraws instead of becoming unknown.
     */
    public function beforeSend(McpConnection $connection, int $actor, int $intentId, string $token): bool
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Pre-send checks must commit independently before any external request.');
        }

        return DB::transaction(function () use ($connection, $actor, $intentId, $token) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->operator($current, $actor);
            $intent = OrderIntent::whereKey($intentId)->where('mcp_connection_id', $current->id)->firstOrFail();
            $account = DB::table('mcp_execution_accounts')->where('id', $intent->execution_account_id)->lockForUpdate()->firstOrFail();
            $dispatch = $this->owned($intentId, $token, ['submitting']);
            $authorized = $current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->bound_account_id &&
                $current->auth_generation === $intent->auth_generation && AccountScope::key($current) === $account->scope;
            $reason = match (true) {
                ! config('mcp.brokerage_writes_enabled') => 'withdrawn_writes_disabled',
                ExecutionControls::state($account->scope)['emergency_disabled'] => 'withdrawn_emergency_disabled',
                $intent->expires_at->lte(CarbonImmutable::now()) => 'withdrawn_deadline_passed',
                ! $authorized => 'withdrawn_authorization_changed',
                ($recovery = app(ExecutionRecoveryFence::class)->reason($account->scope)) !== null => 'withdrawn_'.$recovery,
                default => null,
            };
            if ($reason === null && ($denial = $this->checks->reason($current, $intent)) !== null) {
                $reason = 'withdrawn_'.substr(preg_replace('/[^a-z0-9_]/', '_', strtolower($denial)), 0, 80);
            }
            if ($reason !== null) {
                $this->transition($dispatch, 'withdrawn', $reason);
            }

            return $reason === null;
        });
    }

    /**
     * The claim owner stops before any place request was made, for example after a broker review alert. Only the
     * process that knows nothing was sent may call this; a possible send must use uncertain() instead.
     */
    public function withdraw(McpConnection $connection, int $actor, int $intentId, string $token, string $reason): void
    {
        abort_unless(preg_match('/^withdrawn_[a-z0-9_]{1,80}$/D', $reason) === 1, 422, 'Invalid withdrawal reason.');
        DB::transaction(function () use ($connection, $actor, $intentId, $token, $reason) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->operator($current, $actor);
            $intent = OrderIntent::whereKey($intentId)->where('mcp_connection_id', $current->id)->firstOrFail();
            DB::table('mcp_execution_accounts')->where('id', $intent->execution_account_id)->lockForUpdate()->firstOrFail();
            $this->transition($this->owned($intentId, $token, ['submitting']), 'withdrawn', $reason);
        });
    }

    /**
     * The broker acknowledged the request with its order ID. Binding the ID is what lets reconciliation and external
     * order detection recognise the order as this app's. It records acceptance, not a fill.
     */
    public function accepted(McpConnection $connection, int $actor, int $intentId, string $token, string $brokerOrderId): void
    {
        abort_unless($brokerOrderId !== '' && strlen($brokerOrderId) <= 128 && ! preg_match('/\s/', $brokerOrderId), 422, 'Invalid broker order reference.');
        DB::transaction(function () use ($connection, $actor, $intentId, $token, $brokerOrderId) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->operator($current, $actor);
            $intent = OrderIntent::whereKey($intentId)->where('mcp_connection_id', $current->id)->firstOrFail();
            DB::table('mcp_execution_accounts')->where('id', $intent->execution_account_id)->lockForUpdate()->firstOrFail();
            $dispatch = DB::table('mcp_execution_dispatches')->where('intent_id', $intentId)->lockForUpdate()->firstOrFail();
            $bound = BrokerOrderBinding::where('intent_id', $intentId)->first();
            if ($bound) {
                abort_unless($dispatch->claim_token !== null && hash_equals($dispatch->claim_token, $token) &&
                    hash_equals($bound->broker_id_hash, hash('sha256', $brokerOrderId)), 409, 'Intent already bound to a different broker order.');

                return;
            }
            $dispatch = $this->owned($intentId, $token, ['submitting']);
            BrokerOrderBinding::create(['intent_id' => $intentId, 'broker_id_hash' => hash('sha256', $brokerOrderId), 'broker_order_id' => $brokerOrderId,
                'source' => 'submission_response', 'recorded_at' => now()]);
            $this->transition($dispatch, 'accepted', 'broker_acknowledged_order');
        });
    }

    /**
     * The broker routed the request to the customer for approval in the Robinhood app instead of placing it. No order
     * exists yet. The account stays blocked until the approved order is proven by its ref_id or an owner link, or the
     * owner confirms absence after the approval's own expiry. Approval state text is kept verbatim, never interpreted.
     */
    public function approvalPending(McpConnection $connection, int $actor, int $intentId, string $token, string $approvalId, string $state, bool $edited, string $expiresAt): void
    {
        abort_unless($approvalId !== '' && strlen($approvalId) <= 128 && ! preg_match('/\s/', $approvalId) && $state !== '' && strlen($state) <= 64 &&
            preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $expiresAt), 422, 'Invalid broker approval reference.');
        DB::transaction(function () use ($connection, $actor, $intentId, $token, $approvalId, $state, $edited, $expiresAt) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->operator($current, $actor);
            $intent = OrderIntent::whereKey($intentId)->where('mcp_connection_id', $current->id)->firstOrFail();
            DB::table('mcp_execution_accounts')->where('id', $intent->execution_account_id)->lockForUpdate()->firstOrFail();
            if ($prior = ExecutionApproval::where('intent_id', $intentId)->first()) {
                abort_unless(hash_equals($prior->approval_id_hash, hash('sha256', $approvalId)), 409, 'Intent already has a different approval.');
                $this->owned($intentId, $token, ['approval_pending', 'accepted', 'partially_filled', 'filled', 'broker_cancelled', 'rejected', 'absent']);

                return;
            }
            $dispatch = $this->owned($intentId, $token, ['submitting']);
            ExecutionApproval::create(['intent_id' => $intentId, 'approval_id_hash' => hash('sha256', $approvalId), 'approval_id' => $approvalId,
                'initial_state' => $state, 'is_edited' => $edited, 'expires_at' => CarbonImmutable::parse($expiresAt), 'recorded_at' => now()]);
            $this->transition($dispatch, 'approval_pending', 'broker_customer_approval_requested');
        });
    }

    /**
     * Owner resolution of an unknown outcome to an order the broker actually reports. The order must match the
     * intent's symbol, side and quantity, and is reviewed as an external order by the same authenticated action.
     */
    public function link(McpConnection $connection, int $actor, int $intentId, int $externalOrderId, string $request, string $note): void
    {
        Validator::make(['request' => $request, 'note' => $note], ['request' => ['required', 'uuid'], 'note' => ['required', 'string', 'max:500']])->validate();
        DB::transaction(function () use ($connection, $actor, $intentId, $externalOrderId, $request, $note) {
            $current = $this->connection($connection, $actor);
            $intent = OrderIntent::whereKey($intentId)->where('mcp_connection_id', $current->id)->firstOrFail();
            $account = DB::table('mcp_execution_accounts')->where('id', $intent->execution_account_id)->lockForUpdate()->firstOrFail();
            abort_unless($account->scope === AccountScope::key($current), 409, 'Intent authorization changed.');
            $dispatch = DB::table('mcp_execution_dispatches')->where('intent_id', $intentId)->lockForUpdate()->firstOrFail();
            if ($prior = BrokerOrderBinding::where('request_id', $request)->first()) {
                abort_unless($prior->intent_id === $intentId && $prior->external_order_id === $externalOrderId, 409, 'Request already used for a different link.');

                return;
            }
            abort_unless(in_array($dispatch->status, ['unknown', 'approval_pending'], true), 409, 'Only an unknown or approval-pending outcome can be linked.');
            $external = ExternalOrder::whereKey($externalOrderId)->where('scope', $account->scope)->firstOrFail();
            $order = $external->payload['order'] ?? [];
            abort_unless(self::matches($intent, $order, CarbonImmutable::parse($dispatch->claimed_at)), 409, 'Broker order does not match the intent.');
            abort_if(BrokerOrderBinding::where('broker_id_hash', $external->broker_id_hash)->exists(), 409, 'Broker order already linked.');
            BrokerOrderBinding::create(['intent_id' => $intentId, 'broker_id_hash' => $external->broker_id_hash, 'broker_order_id' => $order['id'],
                'source' => 'owner_link', 'actor_id' => $actor, 'external_order_id' => $external->id, 'request_id' => $request, 'recorded_at' => now()]);
            if (! ExternalOrderReview::where('external_order_id', $external->id)->exists()) {
                ExternalOrderReview::create(['external_order_id' => $external->id, 'actor_id' => $actor, 'request_id' => $request,
                    'payload' => ['note' => $note, 'linked_intent_id' => $intentId], 'reviewed_at' => now()]);
            }
            if ($external->mcp_connection_id === $current->id && ! app(ExternalOrders::class)->pending($current)->exists()) {
                app(IncidentRecorder::class)->resolve($current, ExternalOrders::INCIDENT);
            }
            $this->transition($dispatch, 'accepted', 'owner_linked_broker_order');
        });
    }

    /**
     * Owner confirmation that an unknown request, or an approval request that was declined or expired, created no
     * order. Requires a fresh, complete order-history read taken after the submission deadline and any approval expiry
     * that shows no candidate order and no unreviewed external candidate.
     */
    public function absent(McpConnection $connection, int $actor, int $intentId): void
    {
        DB::transaction(function () use ($connection, $actor, $intentId) {
            $current = $this->connection($connection, $actor);
            $intent = OrderIntent::whereKey($intentId)->where('mcp_connection_id', $current->id)->firstOrFail();
            $account = DB::table('mcp_execution_accounts')->where('id', $intent->execution_account_id)->lockForUpdate()->firstOrFail();
            abort_unless($account->scope === AccountScope::key($current), 409, 'Intent authorization changed.');
            $dispatch = DB::table('mcp_execution_dispatches')->where('intent_id', $intentId)->lockForUpdate()->firstOrFail();
            if ($dispatch->status === 'absent') {
                return;
            }
            abort_unless(in_array($dispatch->status, ['unknown', 'approval_pending'], true), 409, 'Only an unknown or approval-pending outcome can be confirmed absent.');
            $after = $intent->expires_at;
            if ($dispatch->status === 'approval_pending') {
                $approval = ExecutionApproval::where('intent_id', $intentId)->firstOrFail();
                $after = $approval->expires_at->gt($after) ? $approval->expires_at : $after;
            }
            $read = BrokerOrderRead::where('mcp_connection_id', $current->id)->where('scope', $account->scope)->where('auth_generation', $current->auth_generation)->latest('id')->first();
            abort_unless($read && $read->complete && ($read->payload['read']['coverage'] ?? null) === 'equities' &&
                ($read->payload['contract_fingerprint'] ?? null) === config('robinhood_mcp.operations.orders.fingerprint') &&
                ! $read->retrieved_at->isFuture() && $read->retrieved_at->gt(CarbonImmutable::now()->subMinutes(5)) &&
                $read->retrieved_at->gte($after->addMinutes(2)), 409, 'A complete order history read after the deadline is required.');
            $claimed = CarbonImmutable::parse($dispatch->claimed_at);
            foreach ($read->payload['read']['records'] ?? [] as $row) {
                abort_if(self::matches($intent, $row['data'] ?? [], $claimed) || (($row['data']['ref_id'] ?? null) === $intent->idempotency_key), 409, 'Broker reports a matching order; link it instead.');
            }
            foreach (app(ExternalOrders::class)->pending($current)->get() as $external) {
                abort_if(self::matches($intent, $external->payload['order'] ?? [], $claimed) || (($external->payload['order']['ref_id'] ?? null) === $intent->idempotency_key), 409, 'An unreviewed matching order exists; link it instead.');
            }
            $this->transition($dispatch, 'absent', 'owner_confirmed_no_broker_order');
        });
    }

    /**
     * Matching evidence: symbol, side, exact quantity, created no earlier than a minute before the claim. Leniently, a
     * missing creation time counts as a match (never as proof of absence); strictly, it must be present.
     */
    public static function matches(OrderIntent $intent, array $order, CarbonImmutable $claimed, bool $strict = false): bool
    {
        $created = $order['created_at'] ?? null;
        try {
            $recent = is_string($created) ? CarbonImmutable::parse($created)->gte($claimed->subMinute()) : ! $strict;
        } catch (\Throwable) {
            $recent = ! $strict;
        }

        return is_string($order['id'] ?? null) && ($order['symbol'] ?? null) === $intent->payload['symbol'] &&
            ($order['side'] ?? null) === strtolower($intent->payload['side']) && $recent &&
            is_string($order['quantity'] ?? null) && preg_match('/^\d+(?:\.\d+)?$/D', $order['quantity']) &&
            Decimal::from($order['quantity'])->compareTo(Decimal::from($intent->payload['quantity'])) === 0;
    }

    private function owned(int $intentId, string $token, array $statuses): object
    {
        $dispatch = DB::table('mcp_execution_dispatches')->where('intent_id', $intentId)->lockForUpdate()->firstOrFail();
        abort_unless($dispatch->claim_token !== null && hash_equals($dispatch->claim_token, $token) && in_array($dispatch->status, $statuses, true), 409, 'Submission ownership changed.');

        return $dispatch;
    }

    /** Local cancellation is allowed only before a claim. Broker cancellation is a separate future operation. */
    public function cancel(McpConnection $connection, int $actor, int $intentId): void
    {
        DB::transaction(function () use ($connection, $actor, $intentId) {
            $current = $this->connection($connection, $actor);
            $intent = $this->intent($current, $intentId);
            DB::table('mcp_execution_accounts')->where('id', $intent->execution_account_id)->lockForUpdate()->firstOrFail();
            $dispatch = DB::table('mcp_execution_dispatches')->where('intent_id', $intentId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($dispatch->status, ['staged', 'cancelled'], true), 409, 'Claimed orders require broker reconciliation.');
            if ($dispatch->status === 'staged') {
                $this->transition($dispatch, 'cancelled', 'unsubmitted_intent_cancelled');
            }
        });
    }

    private function operator(McpConnection $connection, int $actor): void
    {
        abort_unless($connection->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
    }

    private function connection(McpConnection $expected, int $actor): McpConnection
    {
        $current = McpConnection::whereKey($expected->id)->lockForUpdate()->firstOrFail();
        $this->operator($current, $actor);
        abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->bound_account_id &&
            $current->auth_generation === $expected->auth_generation && AccountScope::key($current) === AccountScope::key($expected), 409, 'Account authorization changed.');

        return $current;
    }

    private function intent(McpConnection $connection, int $id): OrderIntent
    {
        $intent = OrderIntent::whereKey($id)->where('mcp_connection_id', $connection->id)->firstOrFail();
        abort_unless($intent->actor_id === $connection->user_id && $intent->auth_generation === $connection->auth_generation &&
            DB::table('mcp_execution_accounts')->where('id', $intent->execution_account_id)->where('scope', AccountScope::key($connection))->exists(), 409, 'Intent authorization changed.');

        return $intent;
    }

    private function transition(object $dispatch, string $status, string $reason, array $extra = []): void
    {
        DB::table('mcp_execution_dispatches')->where('id', $dispatch->id)->update($extra + ['status' => $status, 'version' => $dispatch->version + 1, 'updated_at' => now()]);
        $this->event($dispatch->intent_id, $dispatch->version + 1, $status, $reason);
    }

    private function event(int $intent, int $version, string $status, string $reason): void
    {
        DB::table('mcp_execution_events')->insert(['intent_id' => $intent, 'version' => $version, 'status' => $status, 'reason' => $reason, 'recorded_at' => now()]);
    }

    private function draft(array $draft): array
    {
        $keys = ['type', 'quantity', 'price_bound', 'expires_at', 'policy_fingerprint'];
        abort_unless(count($draft) === count($keys) && array_diff($keys, array_keys($draft)) === [], 422, 'Unexpected intent fields.');
        abort_unless(in_array($draft['type'], ['market', 'limit'], true), 422, 'Unsupported order type.');
        foreach (['quantity', 'price_bound'] as $key) {
            abort_unless(is_string($draft[$key]) && preg_match('/^\d{1,18}(?:\.\d{1,6})?$/D', $draft[$key]) &&
                Decimal::from($draft[$key])->compareTo(Decimal::from('0')) > 0, 422, 'Invalid decimal intent value.');
        }
        abort_unless($draft['type'] !== 'limit' || Decimal::from($draft['quantity'])->compareTo(Decimal::from($draft['quantity'])->rounded(0, RoundingMode::DOWN)) === 0,
            422, 'Fractional limits are unsupported.');
        abort_unless(is_string($draft['policy_fingerprint']) && preg_match('/^[a-f0-9]{64}$/D', $draft['policy_fingerprint']), 422, 'A policy fingerprint is required.');
        abort_unless(is_string($draft['expires_at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $draft['expires_at']), 422, 'Timezone-qualified expiry required.');
        try {
            $expires = CarbonImmutable::parse($draft['expires_at']);
        } catch (\Throwable) {
            abort(422, 'Invalid intent expiry.');
        }

        return ['type' => $draft['type'], 'quantity' => (string) Decimal::from($draft['quantity']), 'price_bound' => (string) Decimal::from($draft['price_bound']),
            'expires_at' => $expires->utc()->toISOString(), 'policy_fingerprint' => $draft['policy_fingerprint'],
            'session' => RobinhoodOrderContract::FRACTIONAL_MARKET_HOURS, 'time_in_force' => 'day'];
    }
}
