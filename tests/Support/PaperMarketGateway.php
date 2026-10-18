<?php

namespace Tests\Support;

use App\Models\McpConnection;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadResult;
use App\Services\Broker\Agentic\TrustedHttp;

final class PaperMarketGateway implements BrokerGateway
{
    public array $calls = [];

    public ?\Closure $respond = null;

    public function read(McpConnection $connection, Operation $operation, array $filters = []): ReadResult
    {
        $this->calls[] = [$operation, $filters];
        if ($this->respond) {
            return ($this->respond)($connection, $operation, $filters);
        }
        $fields = $operation === Operation::Quotes
            ? ['symbol' => $filters['symbol'], 'bid' => '99', 'ask' => '100', 'bid_as_of' => now()->subSeconds(10)->toIso8601String(), 'ask_as_of' => now()->subSeconds(2)->toIso8601String()]
            : ['symbol' => $filters['symbol'], 'id' => 'synthetic-instrument', 'name' => 'Synthetic provider name.'];

        return new ReadResult(TrustedHttp::RESOURCE, now()->toDateTimeImmutable(), [BrokerRecord::from($operation, $fields, null)], true);
    }
}
