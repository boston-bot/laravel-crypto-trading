<?php

namespace Tests\Support;

use App\Models\McpConnection;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\ReadResult;
use App\Services\Broker\Agentic\TrustedHttp;
use Illuminate\Support\Str;

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

    public bool $partialAccounts = false;

    /** Robinhood's customer-approval setting; null makes the read fail. */
    public ?bool $approvalRequired = true;

    public ?array $accountPatch = [];

    public function read(McpConnection $connection, Operation $operation, array $filters = []): ReadResult
    {
        $account = $connection->bound_account_id;
        $rows = match ($operation) {
            Operation::Accounts => $this->accountPatch === null ? throw new ReadFailure(ReadError::Transport) : [array_replace([
                'id' => $account, 'account_kind' => 'agentic', 'agentic_allowed' => true, 'status' => 'active', 'deactivated' => false,
                'permanently_deactivated' => false, 'management_type' => 'self_directed', 'brokerage_account_type' => 'individual', 'account_type' => 'limited_margin'], $this->accountPatch)],
            Operation::Portfolio => [['account_id' => $account, 'equity' => $this->equity, 'cash' => $this->buyingPower, 'buying_power' => $this->buyingPower]],
            Operation::Positions => array_map(fn ($symbol, $p) => ['account_id' => $account, 'symbol' => $symbol, 'quantity' => $p[0], 'market_value' => $p[1]],
                array_keys($this->positions), $this->positions),
            Operation::Orders => array_map(fn ($row) => $row + ['account_id' => $account, 'instrument_id' => 'fixture-instrument'], $this->orders),
            Operation::TradeApprovalSetting => $this->approvalRequired === null ? throw new ReadFailure(ReadError::Transport)
                : [['account_id' => $account, 'setting_account' => $account, 'human_must_approve_trades' => $this->approvalRequired]],
            default => null,
        };
        if ($rows === null) {
            return (new MonitoringGateway)->read($connection, $operation, $filters);
        }
        $orders = in_array($operation, [Operation::Orders, Operation::TradeApprovalSetting, Operation::Accounts], true);

        return new ReadResult($orders ? TrustedHttp::RESOURCE : 'fake:synthetic-not-broker-data', now()->toDateTimeImmutable(),
            array_map(fn ($row) => BrokerRecord::from($operation, $row, now()->toDateTimeImmutable()), $rows),
            ! ($operation === Operation::Accounts ? $this->partialAccounts : ($orders && $this->partialOrders)), coverage: match ($operation) {
                Operation::Portfolio => 'account_totals', Operation::TradeApprovalSetting => 'account_setting', default => 'equities'
            });
    }

    public function within(McpConnection $connection, callable $work): mixed
    {
        return $work();
    }

    /** One app order row as Robinhood reports it. Executions: list of [id, quantity, price]. */
    public static function order(string $id, string $state, string $quantity, string $filled = '0', array $executions = [], string $symbol = 'DEMO', string $side = 'buy', ?string $created = null, ?string $ref = null): array
    {
        return ['id' => $id, 'ref_id' => $ref ?? (string) Str::uuid(), 'symbol' => $symbol, 'side' => $side, 'status' => $state, 'order_type' => 'market', 'quantity' => $quantity,
            'filled_quantity' => $filled, 'average_price' => $executions === [] ? null : $executions[0][2], 'fees' => '0.00',
            'created_at' => $created ?? now()->toIso8601String(), 'executions' => array_map(fn ($e) => ['id' => $e[0], 'quantity' => $e[1], 'price' => $e[2],
                'fees' => '0.00', 'timestamp' => now()->toIso8601String()], $executions)];
    }
}
