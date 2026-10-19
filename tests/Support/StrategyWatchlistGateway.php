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

    /** Optional several lists: name => ['id' => list id, 'members' => [...]]. Empty uses the single list above. */
    public array $lists = [];

    /** Fail only reads of this list name (null with $fail = true fails everything). */
    public ?string $failList = null;

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
        if ($this->fail && $this->failList === null) {
            throw new ReadFailure(ReadError::Transport);
        }
        $lists = $this->lists ?: ['Strategy Candidates' => ['id' => $this->listId, 'members' => $this->members]];
        if ($operation === Operation::Watchlists) {
            $rows = array_map(fn ($name, $list) => ['id' => $list['id'], 'name' => $name, 'owner_type' => 'user', 'item_count' => count($list['members'])], array_keys($lists), $lists);
        } elseif ($operation === Operation::WatchlistItems) {
            $name = array_search($filters['list_id'], array_column($lists, 'id', null), true);
            $name = array_keys($lists)[$name];
            if ($this->fail && $this->failList === $name) {
                throw new ReadFailure(ReadError::Transport);
            }
            $rows = array_map(fn ($m) => $m + ['list_id' => $filters['list_id']], $lists[$name]['members']);
            ($this->during) && ($this->during)();
        } else {
            throw new \LogicException('Unexpected broker operation.');
        }

        return new ReadResult(TrustedHttp::RESOURCE, CarbonImmutable::now(), array_map(fn ($row) => BrokerRecord::from($operation, $row, null), $rows), true);
    }
}
