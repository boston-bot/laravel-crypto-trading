<?php

namespace Tests\Support;

use App\Models\McpConnection;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadResult;

final class MonitoringGateway implements BrokerGateway
{
    public bool $partial = false;

    public bool $empty = false;

    public bool $sourceTimes = true;

    public array $calls = [];

    public ?\Closure $beforeRead = null;

    public function read(McpConnection $connection, Operation $operation, array $filters = []): ReadResult
    {
        $this->calls[] = $operation->value;
        if ($this->beforeRead) {
            ($this->beforeRead)($connection, $operation);
        }
        $rows = match ($operation) {
            Operation::Portfolio => [['account_id' => $connection->bound_account_id, 'equity' => '1000.00', 'cash' => '600.00', 'buying_power' => '550.00']],
            Operation::Positions => $this->empty ? [] : [['account_id' => $connection->bound_account_id, 'symbol' => 'SYNTH', 'quantity' => '2.000000000000000001', 'market_value' => '400.00']],
            Operation::Orders => [['id' => 'synthetic-order', 'account_id' => $connection->bound_account_id, 'instrument_id' => 'synthetic-instrument', 'symbol' => 'SYNTH', 'status' => 'partially_filled', 'side' => 'buy', 'order_type' => 'limit', 'quantity' => '2', 'filled_quantity' => '0.5', 'price' => '100']],
            Operation::Quotes => [['symbol' => 'SYNTH', 'last' => '200', 'last_session' => 'regular']],
            default => [],
        };

        return new ReadResult('fake:synthetic-not-broker-data', now()->toDateTimeImmutable(),
            array_map(fn ($row) => BrokerRecord::from($operation, $row, $this->sourceTimes ? now()->toDateTimeImmutable() : null), $rows),
            ! ($this->partial && $operation === Operation::Positions), coverage: $operation === Operation::Portfolio ? 'account_totals' : 'equities');
    }
}
