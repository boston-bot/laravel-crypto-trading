<?php

namespace App\Services\Execution;

use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Models\User;
use App\Services\Broker\Agentic\RobinhoodOrderContract;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\SignalRules;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Ramsey\Uuid\Uuid;

/** Durable journal/outbox only. No network, scheduler, live balances or broker gateway. */
final class ExecutionJournal
{
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
            abort_unless($signal->status === 'signal' && in_array($signal->side, ['BUY', 'SELL'], true) &&
                $signal->asset_class === 'equity' && $signal->venue === 'robinhood_agentic' && $signal->rule_version === SignalRules::VERSION &&
                $cycle->mcp_connection_id === $current->id && $cycle->scope === $scope && $cycle->auth_generation === $current->auth_generation, 409, 'Signal provenance is invalid.');
            $payload = ['contract' => 'execution_intent_v1', 'mode' => 'live_intent_unapproved',
                'symbol' => $signal->symbol, 'side' => $signal->side, 'signal_id' => $signal->id,
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
            // Includes the same account through a different connection or authorization generation.
            $unresolved = DB::table('mcp_execution_dispatches as d')->join('mcp_execution_intents as i', 'i.id', '=', 'd.intent_id')
                ->where('i.execution_account_id', $intent->execution_account_id)->whereIn('d.status', ['submitting', 'unknown'])->exists();
            if ($unresolved) {
                return null;
            }
            abort_unless(config('mcp.brokerage_writes_enabled'), 409, 'Brokerage writes are disabled.');
            abort_if($reason = $this->checks->reason($intent), 409, $reason ?? 'Submission denied.');
            $token = (string) Str::uuid();
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
