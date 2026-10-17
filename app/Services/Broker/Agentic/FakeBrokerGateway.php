<?php

namespace App\Services\Broker\Agentic;

use App\Models\McpConnection;
use DateTimeImmutable;

final class FakeBrokerGateway implements BrokerGateway
{
    public function __construct(private array $records = []) {}

    public function read(McpConnection $connection, Operation $operation, array $filters = []): ReadResult
    {
        if (! array_key_exists($operation->value, $this->records)) {
            throw new ReadFailure(ReadError::Unsupported);
        }

        return new ReadResult('fake:synthetic-not-broker-data', new DateTimeImmutable,
            array_map(fn ($row) => BrokerRecord::from($operation, $row, null), $this->records[$operation->value]), true);
    }
}
