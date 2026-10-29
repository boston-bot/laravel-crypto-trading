<?php

namespace App\Services\Execution;

use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Services\Broker\Agentic\Capabilities;
use App\Services\Broker\Agentic\McpSession;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\RobinhoodOrderContract;
use App\Services\Broker\Agentic\SessionFactory;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * The only code that can call Robinhood's order tools. Its session's allowlist is review_equity_order and
 * place_equity_order alone, and both tools' live schemas must match the reviewed fingerprints before any call.
 * Arguments come only from the immutable intent; ref_id is the intent's stable idempotency key. No retries.
 */
final class RobinhoodExecutionGateway implements ExecutionGateway
{
    private ?array $open = null;

    public function __construct(private SessionFactory $sessions, private Capabilities $capabilities) {}

    public function within(McpConnection $connection, callable $work): mixed
    {
        if ($this->open !== null) {
            throw new LogicException('One write session at a time.');
        }
        try {
            $session = $this->sessions->openExecution($connection);
        } catch (ReadFailure $failure) {
            throw new ExecutionFailure('session_'.$failure->reason->value, false);
        }
        try {
            try {
                $tools = $this->capabilities->discover($connection, $session);
            } catch (ReadFailure $failure) {
                throw new ExecutionFailure('catalog_'.$failure->reason->value, false);
            }
            foreach (SessionFactory::EXECUTION_TOOLS as $tool) {
                if (! hash_equals(RobinhoodOrderContract::SCHEMA_FINGERPRINT[$tool], $tools[$tool]['fingerprint'] ?? '')) {
                    throw new ExecutionFailure('write_schema_changed', false);
                }
            }
            $this->open = ['connection' => $connection->id, 'session' => $session];

            return $work();
        } finally {
            $this->open = null;
            try {
                $session->close();
            } catch (Throwable) {
                // Closing never changes the order outcome.
            }
        }
    }

    public function review(McpConnection $connection, OrderIntent $intent): array
    {
        $arguments = $this->arguments($connection, $intent);
        try {
            $result = $this->session($connection)->call('review_equity_order', $arguments);
        } catch (Throwable) {
            throw new ExecutionFailure('review_failed', false);
        }
        $data = $result['data'] ?? null;
        if (! is_array($data) || ($data['symbol'] ?? null) !== $arguments['symbol'] || ($data['side'] ?? null) !== $arguments['side'] ||
            ! is_array($data['order_checks'] ?? null)) {
            throw new ExecutionFailure('review_malformed', false);
        }
        $approval = $data['customer_approval_required'] ?? null;

        return ['order_checks' => $data['order_checks'], 'customer_approval_required' => is_bool($approval) ? $approval : null];
    }

    public function place(McpConnection $connection, OrderIntent $intent): array
    {
        $arguments = $this->arguments($connection, $intent) + ['ref_id' => $intent->idempotency_key];
        $session = $this->session($connection);
        $sentAt = CarbonImmutable::now();
        try {
            $result = $session->call('place_equity_order', $arguments);
        } catch (Throwable) {
            throw new ExecutionFailure('place_outcome_unknown', true);
        }
        $data = $result['data'] ?? null;
        $order = is_array($data) ? ($data['order'] ?? null) : null;
        $approval = is_array($data) ? ($data['approval'] ?? null) : null;
        if (is_array($order) && $approval === null) {
            $ref = $order['ref_id'] ?? null;
            $receivedAt = CarbonImmutable::now();
            if (! $this->matchesResponse($intent, $arguments, $order, $sentAt, $receivedAt) || ($ref !== null && (! is_string($ref) || ! Str::isUuid($ref)))) {
                throw new ExecutionFailure('place_order_differs', true);
            }

            // Ownership comes from this request's authenticated, JSON-RPC-correlated response and exact terms,
            // not from the echoed ref_id. History-only matching still requires the original reference.
            return ['kind' => 'order', 'order_id' => $order['id'], 'receipt' => [
                'contract' => 'correlated_submission_response_v1', 'scope' => AccountScope::key($connection),
                'auth_generation' => $connection->auth_generation, 'submitted_ref_id' => $intent->idempotency_key,
                'returned_ref_id' => $ref, 'broker_order_id' => $order['id'], 'symbol' => $order['symbol'], 'side' => $order['side'],
                'type' => $order['type'], 'quantity' => $order['quantity'], 'time_in_force' => $order['time_in_force'],
                'market_hours' => $order['market_hours'], 'limit_price' => $arguments['limit_price'] ?? null,
                'broker_created_at' => $order['created_at'], 'sent_at' => $sentAt->toISOString(), 'received_at' => $receivedAt->toISOString(),
            ]];
        }
        if (is_array($approval) && $order === null && is_string($approval['approval_id'] ?? null) && is_string($approval['state'] ?? null) &&
            is_bool($approval['is_edited'] ?? null) && is_string($approval['expires_at'] ?? null)) {
            return ['kind' => 'approval', 'approval_id' => $approval['approval_id'], 'state' => $approval['state'],
                'is_edited' => $approval['is_edited'], 'expires_at' => $approval['expires_at']];
        }
        throw new ExecutionFailure('place_response_unrecognized', true);
    }

    private function matchesResponse(OrderIntent $intent, array $arguments, array $order, CarbonImmutable $sent, CarbonImmutable $received): bool
    {
        if (! is_string($order['id'] ?? null) || strlen($order['id']) > 128 || preg_match('/\s/', $order['id']) ||
            ! ExecutionJournal::matches($intent, $order, $sent, true) ||
            ($order['type'] ?? null) !== $arguments['type'] || ($order['time_in_force'] ?? null) !== $arguments['time_in_force'] ||
            ($order['market_hours'] ?? null) !== $arguments['market_hours'] || ($order['stop_price'] ?? null) !== null ||
            ! is_string($order['created_at'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $order['created_at'])) {
            return false;
        }
        try {
            return CarbonImmutable::parse($order['created_at'])->lte($received) && ($arguments['type'] !== 'limit' ||
                (is_string($order['price'] ?? null) && preg_match('/^\d+(?:\.\d+)?$/D', $order['price']) &&
                    Decimal::from($order['price'])->compareTo(Decimal::from($arguments['limit_price'])) === 0));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function session(McpConnection $connection): McpSession
    {
        if (($this->open['connection'] ?? null) !== $connection->id) {
            throw new ExecutionFailure('write_session_not_open', false);
        }

        return $this->open['session'];
    }

    /** Reviewed contract: regular-hours day orders; fractional quantities only on market orders; limit price from the bound. */
    private function arguments(McpConnection $connection, OrderIntent $intent): array
    {
        $p = $intent->payload;
        $fractional = str_contains($p['quantity'], '.');
        if ($intent->mcp_connection_id !== $connection->id || ! $connection->bound_account_id || ! in_array($p['side'] ?? null, ['BUY', 'SELL'], true) ||
            ! in_array($p['type'] ?? null, ['market', 'limit'], true) || ($p['session'] ?? null) !== RobinhoodOrderContract::FRACTIONAL_MARKET_HOURS ||
            ($p['time_in_force'] ?? null) !== 'day' || ($fractional && ! RobinhoodOrderContract::allowsFractional($p['type']))) {
            throw new ExecutionFailure('intent_terms_unsupported', false);
        }

        return ['account_number' => $connection->bound_account_id, 'symbol' => $p['symbol'], 'side' => strtolower($p['side']), 'type' => $p['type'],
            'quantity' => $p['quantity'], 'time_in_force' => 'gfd', 'market_hours' => 'regular_hours']
            + ($p['type'] === 'limit' ? ['limit_price' => $p['price_bound']] : []);
    }
}
