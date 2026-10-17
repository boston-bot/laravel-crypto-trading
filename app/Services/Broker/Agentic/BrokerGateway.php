<?php

namespace App\Services\Broker\Agentic;

use App\Models\McpConnection;

interface BrokerGateway
{
    public function read(McpConnection $connection, Operation $operation, array $filters = []): ReadResult;
}
