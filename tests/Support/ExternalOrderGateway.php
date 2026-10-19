<?php

namespace Tests\Support;

use App\Models\McpConnection;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadResult;
use App\Services\Broker\Agentic\TrustedHttp;

/** Synthetic normalized equity-order observations. Never contacts a broker. */
final class ExternalOrderGateway implements BrokerGateway
{
    public array $orders = [];

    public bool $partial = false;

    public ?\Closure $duringOrders = null;

    public function read(McpConnection $connection, Operation $operation, array $filters = []): ReadResult
    {
        if ($operation !== Operation::Orders) {
            return (new MonitoringGateway)->read($connection, $operation, $filters);
        }
        $rows = array_map(fn ($row) => BrokerRecord::from($operation, $row + ['account_id' => $connection->bound_account_id,
            'instrument_id' => 'fixture-instrument', 'symbol' => 'DEMO', 'side' => 'buy', 'status' => 'filled'], null), $this->orders);
        ($this->duringOrders) && ($this->duringOrders)();

        return new ReadResult(TrustedHttp::RESOURCE, now()->toDateTimeImmutable(), $rows, ! $this->partial, coverage: 'equities');
    }

    public function within(McpConnection $connection, callable $work): mixed
    {
        return $work();
    }
}
