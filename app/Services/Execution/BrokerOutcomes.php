<?php

namespace App\Services\Execution;

use App\Models\Execution\BrokerOrderBinding;
use App\Models\Execution\ExecutionFill;
use App\Models\Execution\ExecutionOutcome;
use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Models\Monitoring\BrokerOrderRead;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\IncidentRecorder;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Broker-confirmed outcomes for app-bound orders, from stored read-only order history. No network. Only an order
 * bound by its broker ID is reconciled; anything inconsistent is left unchanged and raises a critical incident,
 * which blocks every new live claim until the owner reviews it. Simulated paper fills are never written here.
 */
final class BrokerOutcomes
{
    public const MISMATCH = 'execution_reconciliation_mismatch';

    public const MISSING = 'execution_order_missing_from_history';

    /** Robinhood order states (RobinhoodOrderContract::ORDER_STATES plus legacy spellings). An open state with a partial cumulative quantity is a partial fill. */
    public const STATES = ['new' => 'accepted', 'queued' => 'accepted', 'unconfirmed' => 'accepted', 'confirmed' => 'accepted', 'pending' => 'accepted',
        'partially_filled' => 'partially_filled', 'filled' => 'filled', 'cancelled' => 'broker_cancelled', 'canceled' => 'broker_cancelled',
        'expired' => 'broker_cancelled', 'voided' => 'broker_cancelled', 'rejected' => 'rejected', 'failed' => 'rejected'];

    private const NEXT = ['accepted' => ['accepted', 'partially_filled', 'filled', 'broker_cancelled', 'rejected'],
        'partially_filled' => ['partially_filled', 'filled', 'broker_cancelled']];

    public function __construct(private IncidentRecorder $incidents) {}

    /**
     * Binds orders that carry an unresolved intent's ref_id (its idempotency key) before external-order classification.
     * The broker deduplicates by ref_id, but proof also needs the intent's symbol, side and exact quantity and a
     * creation time after the claim. A ref_id match that differs is a contradiction (for example an approval the
     * customer edited): it stays external and raises a blocking incident. Caller holds the connection-row lock.
     */
    public function bind(McpConnection $connection, string $scope, array $records): void
    {
        $account = DB::table('mcp_execution_accounts')->where('scope', $scope)->lockForUpdate()->first();
        if (! $account) {
            return;
        }
        $unbound = OrderIntent::where('execution_account_id', $account->id)->whereNotIn('id', BrokerOrderBinding::select('intent_id'))
            ->whereIn('id', DB::table('mcp_execution_dispatches')->whereIn('status', ExecutionJournal::UNBOUND)->select('intent_id'))->get()->keyBy(fn ($intent) => strtolower($intent->idempotency_key));
        foreach ($records as $row) {
            $order = $row['data'] ?? [];
            $ref = is_string($order['ref_id'] ?? null) ? strtolower($order['ref_id']) : null;
            $intent = $ref !== null ? $unbound[$ref] ?? null : null;
            if (! $intent || ! is_string($order['id'] ?? null) || $order['id'] === '') {
                continue;
            }
            $dispatch = DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', $order['id']);
            if (! in_array($dispatch->status, ExecutionJournal::UNBOUND, true) || BrokerOrderBinding::where('intent_id', $intent->id)->exists()) {
                continue;
            }
            if (! ExecutionJournal::matches($intent, $order, CarbonImmutable::parse($dispatch->claimed_at), true) || BrokerOrderBinding::where('broker_id_hash', $hash)->exists()) {
                $this->incidents->record($connection, self::MISMATCH, 'critical', 'execution');

                continue;
            }
            BrokerOrderBinding::create(['intent_id' => $intent->id, 'broker_id_hash' => $hash, 'broker_order_id' => $order['id'],
                'source' => 'ref_id_match', 'recorded_at' => now()]);
            DB::table('mcp_execution_dispatches')->where('id', $dispatch->id)->update(['status' => 'accepted', 'version' => $dispatch->version + 1, 'updated_at' => now()]);
            DB::table('mcp_execution_events')->insert(['intent_id' => $intent->id, 'version' => $dispatch->version + 1, 'status' => 'accepted',
                'reason' => 'broker_order_matched_ref_id', 'recorded_at' => now()]);
            unset($unbound[$ref]);
        }
    }

    /** Caller holds the connection-row lock and has verified the read's account binding and freshness. */
    public function record(McpConnection $connection, BrokerOrderRead $read): void
    {
        $account = DB::table('mcp_execution_accounts')->where('scope', $read->scope)->lockForUpdate()->first();
        if (! $account) {
            return;
        }
        $orders = [];
        foreach ($read->payload['read']['records'] ?? [] as $row) {
            if (is_string($row['data']['id'] ?? null)) {
                $orders[hash('sha256', $row['data']['id'])] = ['order' => $row['data'], 'source_as_of' => $row['source_as_of'] ?? null];
            }
        }
        $bindings = BrokerOrderBinding::whereIn('intent_id', OrderIntent::where('execution_account_id', $account->id)->select('id'))->get();
        foreach ($bindings as $binding) {
            $dispatch = DB::table('mcp_execution_dispatches')->where('intent_id', $binding->intent_id)->lockForUpdate()->firstOrFail();
            if (! isset($orders[$binding->broker_id_hash])) {
                // A complete history that omits an open app order is itself an inconsistency; the claim block stays.
                if ($read->complete && isset(self::NEXT[$dispatch->status])) {
                    $this->incidents->record($connection, self::MISSING, 'critical', 'execution');
                }

                continue;
            }
            $intent = OrderIntent::findOrFail($binding->intent_id);
            $observed = $this->observe($intent, $orders[$binding->broker_id_hash]['order']);
            $latest = ExecutionOutcome::where('intent_id', $intent->id)->latest('id')->first();
            if ($observed !== null && $latest && $this->same($latest, $observed)) {
                continue;
            }
            if ($observed === null || ! in_array($observed['status'], self::NEXT[$dispatch->status] ?? [], true) ||
                ($latest && Decimal::from($observed['filled'])->compareTo(Decimal::from((string) $latest->filled_quantity)) < 0) ||
                ! $this->fillsConsistent($intent, $observed['executions'])) {
                $this->incidents->record($connection, self::MISMATCH, 'critical', 'execution');

                continue;
            }
            $outcome = ExecutionOutcome::create(['intent_id' => $intent->id, 'broker_order_read_id' => $read->id, 'broker_state' => $observed['state'],
                'status' => $observed['status'], 'filled_quantity' => $observed['filled'], 'average_price' => $observed['average_price'], 'fees' => $observed['fees'],
                'source_as_of' => $orders[$binding->broker_id_hash]['source_as_of'], 'retrieved_at' => $read->retrieved_at, 'recorded_at' => now()]);
            foreach ($observed['executions'] as $execution) {
                $hash = hash('sha256', $execution['id']);
                if (ExecutionFill::where('execution_id_hash', $hash)->exists()) {
                    continue;
                }
                ExecutionFill::create(['intent_id' => $intent->id, 'outcome_id' => $outcome->id, 'execution_account_id' => $account->id,
                    'execution_id_hash' => $hash, 'symbol' => $intent->payload['symbol'], 'side' => $intent->payload['side'],
                    'quantity' => $execution['quantity'], 'price' => $execution['price'], 'fees' => $execution['fees'],
                    'executed_at' => CarbonImmutable::parse($execution['timestamp']), 'recorded_at' => now()]);
            }
            if ($observed['status'] !== $dispatch->status) {
                DB::table('mcp_execution_dispatches')->where('id', $dispatch->id)->update(['status' => $observed['status'], 'version' => $dispatch->version + 1, 'updated_at' => now()]);
                DB::table('mcp_execution_events')->insert(['intent_id' => $intent->id, 'version' => $dispatch->version + 1, 'status' => $observed['status'],
                    'reason' => 'broker_reported_'.$observed['state'], 'recorded_at' => now()]);
            }
        }
    }

    /** Owner review. A contradiction that persists is recorded again, reopening the block, on the next order read. */
    public function review(McpConnection $connection, int $actor, string $key): void
    {
        abort_unless(in_array($key, [self::MISMATCH, self::MISSING], true), 422, 'Not an execution reconciliation incident.');
        DB::transaction(function () use ($connection, $actor, $key) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account binding changed.');
            $this->incidents->resolve($current, $key);
        });
    }

    /** Null when the broker record contradicts the intent or itself. */
    private function observe(OrderIntent $intent, array $order): ?array
    {
        $p = $intent->payload;
        $state = $order['status'] ?? null;
        if (! is_string($state) || ! isset(self::STATES[$state]) || ($order['symbol'] ?? null) !== $p['symbol'] || ($order['side'] ?? null) !== strtolower($p['side'])) {
            return null;
        }
        try {
            $quantity = Decimal::from($order['quantity'] ?? null);
            $filled = Decimal::from($order['filled_quantity'] ?? '0');
            $average = ($order['average_price'] ?? null) === null ? null : Decimal::from($order['average_price']);
            $fees = ($order['fees'] ?? null) === null ? null : Decimal::from($order['fees']);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $zero = Decimal::from('0');
        $status = self::STATES[$state];
        if ($status === 'accepted' && $filled->compareTo($zero) > 0) {
            $status = 'partially_filled';
        }
        $complete = $filled->compareTo($quantity) === 0;
        if ($quantity->compareTo(Decimal::from($p['quantity'])) !== 0 || $filled->compareTo($quantity) > 0 ||
            ($status === 'filled' && ! $complete) || ($status === 'rejected' && $filled->compareTo($zero) !== 0) ||
            ($status === 'partially_filled' && ($complete || $filled->compareTo($zero) === 0)) ||
            ($filled->compareTo($zero) > 0 && ($average === null || $average->compareTo($zero) <= 0))) {
            return null;
        }
        $executions = $order['executions'] ?? null;
        if (! is_array($executions)) {
            return $filled->compareTo($zero) === 0 ? ['state' => $state, 'status' => $status, 'filled' => (string) $filled, 'average_price' => null,
                'fees' => $fees === null ? null : (string) $fees, 'executions' => []] : null; // Fills without executions are unverifiable.
        }
        $sum = $zero;
        foreach ($executions as $execution) {
            if (Decimal::from($execution['quantity'])->compareTo($zero) <= 0 || Decimal::from($execution['price'])->compareTo($zero) <= 0) {
                return null;
            }
            $sum = $sum->plus(Decimal::from($execution['quantity']));
        }
        if ($sum->compareTo($filled) !== 0) {
            return null;
        }

        return ['state' => $state, 'status' => $status, 'filled' => (string) $filled, 'average_price' => $average === null ? null : (string) $average,
            'fees' => $fees === null ? null : (string) $fees, 'executions' => $executions];
    }

    private function same(ExecutionOutcome $latest, array $observed): bool
    {
        $equal = fn ($a, $b) => $a === null || $b === null ? $a === $b : Decimal::from((string) $a)->compareTo(Decimal::from((string) $b)) === 0;

        return $latest->status === $observed['status'] && $latest->broker_state === $observed['state'] && $equal($latest->filled_quantity, $observed['filled']) &&
            $equal($latest->average_price, $observed['average_price']) && $equal($latest->fees, $observed['fees']);
    }

    /** A known execution ID must keep its quantity, price, fees and owning intent. */
    private function fillsConsistent(OrderIntent $intent, array $executions): bool
    {
        foreach ($executions as $execution) {
            $known = ExecutionFill::where('execution_id_hash', hash('sha256', $execution['id']))->first();
            if ($known && ($known->intent_id !== $intent->id || Decimal::from((string) $known->quantity)->compareTo(Decimal::from($execution['quantity'])) !== 0 ||
                Decimal::from((string) $known->price)->compareTo(Decimal::from($execution['price'])) !== 0 ||
                Decimal::from((string) $known->fees)->compareTo(Decimal::from($execution['fees'])) !== 0)) {
                return false;
            }
        }

        return true;
    }
}
