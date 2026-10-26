<?php

namespace Tests\Support;

use App\Models\McpConnection;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadResult;
use App\Services\Broker\Agentic\TrustedHttp;

/** Synthetic broker-confirmed account state and order history for live boundary tests. Never contacts a broker. */
final class LiveBrokerGateway implements BrokerGateway
{
    public string $equity = '1000.00';

    public string $buyingPower = '550.00';

    /** symbol => [quantity, market value] */
    public array $positions = ['SYNTH' => ['2', '400.00']];

    /** Normalized order rows; account and instrument are filled in. */
    public array $orders = [];

    public bool $partialOrders = false;

    public function read(McpConnection $connection, Operation $operation, array $filters = []): ReadResult
    {
        $account = $connection->bound_account_id;
        $rows = match ($operation) {
            Operation::Portfolio => [['account_id' => $account, 'equity' => $this->equity, 'cash' => $this->buyingPower, 'buying_power' => $this->buyingPower]],
            Operation::Positions => array_map(fn ($symbol, $p) => ['account_id' => $account, 'symbol' => $symbol, 'quantity' => $p[0], 'market_value' => $p[1]],
                array_keys($this->positions), $this->positions),
            Operation::Orders => array_map(fn ($row) => $row + ['account_id' => $account, 'instrument_id' => 'fixture-instrument'], $this->orders),
            default => null,
        };
        if ($rows === null) {
            return (new MonitoringGateway)->read($connection, $operation, $filters);
        }
        $orders = $operation === Operation::Orders;

        return new ReadResult($orders ? TrustedHttp::RESOURCE : 'fake:synthetic-not-broker-data', now()->toDateTimeImmutable(),
            array_map(fn ($row) => BrokerRecord::from($operation, $row, now()->toDateTimeImmutable()), $rows),
            ! ($orders && $this->partialOrders), coverage: $operation === Operation::Portfolio ? 'account_totals' : 'equities');
    }

    public function within(McpConnection $connection, callable $work): mixed
    {
        return $work();
    }

    /** One app order row as Robinhood reports it. Executions: list of [id, quantity, price]. */
    public static function order(string $id, string $state, string $quantity, string $filled = '0', array $executions = [], string $symbol = 'DEMO', string $side = 'buy', ?string $created = null): array
    {
        return ['id' => $id, 'symbol' => $symbol, 'side' => $side, 'status' => $state, 'order_type' => 'market', 'quantity' => $quantity,
            'filled_quantity' => $filled, 'average_price' => $executions === [] ? null : $executions[0][2], 'fees' => '0.00',
            'created_at' => $created ?? now()->toIso8601String(), 'executions' => array_map(fn ($e) => ['id' => $e[0], 'quantity' => $e[1], 'price' => $e[2],
                'fees' => '0.00', 'timestamp' => now()->toIso8601String()], $executions)];
    }
}
