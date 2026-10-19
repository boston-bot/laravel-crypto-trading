<?php

namespace App\Services\Broker\Agentic;

use App\Models\McpConnection;

interface BrokerGateway
{
    public function read(McpConnection $connection, Operation $operation, array $filters = []): ReadResult;

    /** Run several reads for one connection over a shared session where the implementation supports it. */
    public function within(McpConnection $connection, callable $work): mixed;
}
