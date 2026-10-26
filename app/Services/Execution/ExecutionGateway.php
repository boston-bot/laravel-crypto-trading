<?php

namespace App\Services\Execution;

use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;

/** Broker writes, separate from BrokerGateway reads. Only ExecutionDispatcher may use it. */
interface ExecutionGateway
{
    /** One write session for one dispatch; review and place must run inside it. */
    public function within(McpConnection $connection, callable $work): mixed;

    /** @return array{order_checks: array, customer_approval_required: ?bool} Never places an order. */
    public function review(McpConnection $connection, OrderIntent $intent): array;

    /**
     * @return array{kind: 'order', order_id: string}|array{kind: 'approval', approval_id: string, state: string, is_edited: bool, expires_at: string}
     *
     * @throws ExecutionFailure with maybeSent true for any failure once the request may have been sent
     */
    public function place(McpConnection $connection, OrderIntent $intent): array;
}
