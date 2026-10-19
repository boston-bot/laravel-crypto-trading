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
use Carbon\CarbonImmutable;
use Closure;

/** Synthetic reviewed-tool DTOs; never opens a connection. */
class StrategyWatchlistGateway implements BrokerGateway
{
    public array $members = [['id' => 'fixture-equity', 'object_type' => 'instrument', 'symbol' => 'TEST']];

    public string $listId = 'fixture-list';

    public bool $fail = false;

    public int $calls = 0;

    public ?Closure $during = null;

    public function within(McpConnection $connection, callable $work): mixed
    {
        return $work();
    }

    public function read(McpConnection $connection, Operation $operation, array $filters = []): ReadResult
    {
        $this->calls++;
        if ($this->fail) {
            throw new ReadFailure(ReadError::Transport);
        }
        if ($operation === Operation::Watchlists) {
            $rows = [['id' => $this->listId, 'name' => 'Strategy Candidates', 'owner_type' => 'user', 'item_count' => count($this->members)]];
        } elseif ($operation === Operation::WatchlistItems) {
            $rows = array_map(fn ($m) => $m + ['list_id' => $this->listId], $this->members);
            ($this->during) && ($this->during)();
        } else {
            throw new \LogicException('Unexpected broker operation.');
        }

        return new ReadResult(TrustedHttp::RESOURCE, CarbonImmutable::now(), array_map(fn ($row) => BrokerRecord::from($operation, $row, null), $rows), true);
    }
}
