<?php

namespace App\Services\Execution;

use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Services\Monitoring\AccountScope;
use Throwable;

/**
 * One staged intent through the live boundary: claim (all submission checks), broker review, the last local recheck,
 * then a single place request. Anything before the place request withdraws; anything during or after it is unknown
 * and is resolved by ref_id reconciliation, an owner link or confirmed absence. Never retried.
 */
final class ExecutionDispatcher
{
    public function __construct(private ExecutionJournal $journal, private ExecutionGateway $gateway) {}

    /** @return string not_claimed, withdrawn, accepted, approval_pending or unknown */
    public function dispatch(McpConnection $connection, int $actor, int $intentId): string
    {
        $token = $this->journal->claim($connection, $actor, $intentId);
        if ($token === null) {
            return 'not_claimed';
        }
        $intent = OrderIntent::findOrFail($intentId);
        $attempted = false;
        try {
            return $this->gateway->within($connection, function () use ($connection, $actor, $intent, $token, &$attempted) {
                $review = $this->gateway->review($connection, $intent);
                if ($review['order_checks'] !== []) {
                    // review_equity_order's guide applies whenever order_checks is non-empty; the app never overrides an alert.
                    $this->journal->withdraw($connection, $actor, $intent->id, $token, 'withdrawn_broker_order_checks');

                    return 'withdrawn';
                }
                $expected = LivePolicies::current(AccountScope::key($connection))?->payload['require_broker_approval'] ?? null;
                if ($review['customer_approval_required'] !== null && $review['customer_approval_required'] !== $expected) {
                    $this->journal->withdraw($connection, $actor, $intent->id, $token, 'withdrawn_broker_approval_mode_differs');

                    return 'withdrawn';
                }
                if (! $this->journal->beforeSend($connection, $actor, $intent->id, $token)) {
                    return 'withdrawn';
                }
                $attempted = true;
                $result = $this->gateway->place($connection, $intent);
                if ($result['kind'] === 'order') {
                    $this->journal->accepted($connection, $actor, $intent->id, $token, $result['order_id']);

                    return 'accepted';
                }
                $this->journal->approvalPending($connection, $actor, $intent->id, $token, $result['approval_id'], $result['state'], $result['is_edited'], $result['expires_at']);

                return 'approval_pending';
            });
        } catch (Throwable $error) {
            if ($attempted || ($error instanceof ExecutionFailure && $error->maybeSent)) {
                $this->journal->uncertain($connection, $actor, $intent->id, $token);

                return 'unknown';
            }
            $reason = $error instanceof ExecutionFailure ? $error->reason : 'dispatch_failed';
            $this->journal->withdraw($connection, $actor, $intent->id, $token, 'withdrawn_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($reason)));

            return 'withdrawn';
        }
    }
}
