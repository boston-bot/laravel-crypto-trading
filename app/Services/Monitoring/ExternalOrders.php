<?php

namespace App\Services\Monitoring;

use App\Models\Execution\BrokerOrderBinding;
use App\Models\McpConnection;
use App\Models\Monitoring\BrokerOrderRead;
use App\Models\Monitoring\ExternalOrder;
use App\Models\Monitoring\ExternalOrderReview;
use App\Models\Monitoring\Snapshot;
use App\Models\User;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\Execution\BrokerOutcomes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Broker evidence cannot assert app ownership; only a broker ID the app bound at its execution boundary can. */
final class ExternalOrders
{
    public const INCIDENT = 'external_orders_review_required';

    public function __construct(private IncidentRecorder $incidents) {}

    /** Persist even a partial order page: an observed order is evidence despite missing coverage. No network calls. */
    public function record(McpConnection $connection, Snapshot $snapshot, array $read, string $claim): void
    {
        if (! config('mcp.external_order_detection_enabled') || $connection->account_role !== 'agentic_monitoring') {
            return;
        }
        Validator::make(['claim' => $claim], ['claim' => ['required', 'uuid']])->validate();
        DB::transaction(function () use ($connection, $snapshot, $read, $claim) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'connected' || $current->account_role !== 'agentic_monitoring' || $current->auth_generation !== $connection->auth_generation || ! $current->bound_account_id ||
                AccountScope::key($current) !== $snapshot->scope || $snapshot->mcp_connection_id !== $current->id ||
                ($read['source'] ?? null) !== TrustedHttp::RESOURCE || ! is_array($read['records'] ?? null)) {
                throw new ReadFailure(ReadError::AccountMismatch);
            }
            if (BrokerOrderRead::where('snapshot_id', $snapshot->id)->where('read_claim', $claim)->exists()) {
                return;
            }
            $retrieved = CarbonImmutable::parse($read['retrieved_at']);
            if ($retrieved->isFuture() || $retrieved->lt(CarbonImmutable::now()->subMinutes(5))) {
                throw new ReadFailure(ReadError::Stale);
            }
            foreach ($read['records'] as $row) {
                $order = $row['data'] ?? [];
                if (($order['account_id'] ?? null) !== $current->bound_account_id || ! is_string($order['id'] ?? null) || $order['id'] === '') {
                    throw new ReadFailure(ReadError::AccountMismatch);
                }
                // Only a broker ID bound by this app's own submission response or an owner link is the app's order.
                // Every other status counts, including already filled/cancelled orders and IDs resembling paper/legacy IDs.
                if ($this->appOrder(hash('sha256', $order['id']), $snapshot->scope)) {
                    continue;
                }
                ExternalOrder::firstOrCreate(['mcp_connection_id' => $current->id, 'scope' => $snapshot->scope,
                    'broker_id_hash' => hash('sha256', $order['id'])], ['snapshot_id' => $snapshot->id,
                        'auth_generation' => $current->auth_generation, 'payload' => ['order' => $order, 'source_as_of' => $row['source_as_of'] ?? null,
                            'source' => $read['source'], 'classification_rule' => 'app_bound_broker_ids_excluded_v1'],
                        'retrieved_at' => $retrieved, 'detected_at' => now()]);
            }
            $stored = BrokerOrderRead::create(['mcp_connection_id' => $current->id, 'scope' => $snapshot->scope, 'snapshot_id' => $snapshot->id,
                'read_claim' => $claim,
                'auth_generation' => $current->auth_generation, 'complete' => ($read['completeness'] ?? null) === 'complete' && ($read['continuation'] ?? null) === null,
                'payload' => ['read' => $read,
                    'contract_fingerprint' => config('robinhood_mcp.operations.orders.fingerprint')], 'retrieved_at' => $retrieved, 'recorded_at' => now()]);
            app(BrokerOutcomes::class)->record($current, $stored);
            if ($this->pending($current)->exists()) {
                $this->incidents->record($current, self::INCIDENT, 'critical', 'external_orders');
            }
        });
    }

    private function appOrder(string $hash, string $scope): bool
    {
        return BrokerOrderBinding::where('broker_id_hash', $hash)->whereIn('intent_id', DB::table('mcp_execution_intents as i')
            ->join('mcp_execution_accounts as a', 'a.id', '=', 'i.execution_account_id')->where('a.scope', $scope)->select('i.id'))->exists();
    }

    public function pending(McpConnection $connection): Builder
    {
        return ExternalOrder::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))
            ->whereNotIn('id', ExternalOrderReview::select('external_order_id'));
    }

    /** Submission callers hold the same connection-row lock as detection/review. Exits never use this gate. */
    public function entryReason(McpConnection $connection): ?string
    {
        if ($this->pending($connection)->exists()) {
            return self::INCIDENT; // A configuration change or reconnect cannot clear a latched incident.
        }
        if (! config('mcp.external_order_detection_enabled') || $connection->account_role !== 'agentic_monitoring') {
            return null;
        }
        $latest = BrokerOrderRead::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))
            ->where('auth_generation', $connection->auth_generation)->latest('id')->first();
        if (! $latest || ! $latest->complete || $latest->retrieved_at->isFuture() || $latest->recorded_at->isFuture() ||
            ($latest->payload['read']['source'] ?? null) !== TrustedHttp::RESOURCE || ($latest->payload['read']['coverage'] ?? null) !== 'equities' ||
            ($latest->payload['contract_fingerprint'] ?? null) !== config('robinhood_mcp.operations.orders.fingerprint') ||
            $latest->retrieved_at->lte(CarbonImmutable::now()->subMinutes(5))) {
            return 'broker_order_history_unverified';
        }

        return null;
    }

    public function review(McpConnection $connection, int $actor, int $orderId, string $request, string $note): ExternalOrderReview
    {
        Validator::make(['request' => $request, 'note' => $note], ['request' => ['required', 'uuid'], 'note' => ['required', 'string', 'max:500']])->validate();

        return DB::transaction(function () use ($connection, $actor, $orderId, $request, $note) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->bound_account_id &&
                $connection->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account authorization changed.');
            $order = ExternalOrder::whereKey($orderId)->where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->firstOrFail();
            if ($repeat = ExternalOrderReview::where('request_id', $request)->first()) {
                abort_unless($repeat->external_order_id === $order->id && $repeat->actor_id === $actor && $repeat->payload === ['note' => $note], 409, 'Request already used for a different review.');

                return $repeat;
            }
            abort_if(ExternalOrderReview::where('external_order_id', $order->id)->exists(), 409, 'Order already reviewed. Reload the control center.');
            $review = ExternalOrderReview::create(['external_order_id' => $order->id, 'actor_id' => $actor, 'request_id' => $request,
                'payload' => ['note' => $note], 'reviewed_at' => now()]);
            if (! $this->pending($current)->exists()) {
                $this->incidents->resolve($current, self::INCIDENT);
            }

            return $review;
        });
    }
}
