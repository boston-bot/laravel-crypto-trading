<?php

namespace App\Jobs;

use App\Models\McpConnection;
use App\Models\PaperTrading\Portfolio;
use App\Services\Broker\Agentic\BrokerBackoff;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\ExecutionPolicies;
use App\Services\PaperTrading\PaperMarketInputs;
use App\Services\PaperTrading\SymbolUniverse;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A bounded read-only observation; never invokes decisions, handoff or fills. */
class CollectPaperMarketInput implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public int $connectionId, public string $scope, public int $generation,
        public int $executionId, public string $symbol, public string $requestId, public string $scheduledAt)
    {
        $this->onConnection('mcp_monitoring');
        $this->onQueue('monitoring');
    }

    public function uniqueId(): string
    {
        return 'paper-market:'.$this->connectionId.':'.$this->scope.':'.$this->symbol;
    }

    public function handle(PaperMarketInputs $inputs): void
    {
        if (! config('paper.market_collection_enabled') || ! config('paper.market_inputs_enabled') ||
            CarbonImmutable::parse($this->scheduledAt)->lt(now()->subMinutes(5)) || CarbonImmutable::parse($this->scheduledAt)->isFuture()) {
            return;
        }
        $connection = McpConnection::find($this->connectionId);
        if (! $connection || $connection->status !== 'connected' || ! $connection->bound_account_id ||
            $connection->auth_generation !== $this->generation || AccountScope::key($connection) !== $this->scope) {
            return;
        }
        // During a broker rate-limit pause, skip instead of recording an avoidable failure; a later tick retries.
        if (app(BrokerBackoff::class)->remaining($connection->id) > 0) {
            return;
        }
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', $this->scope)->first();
        $execution = $portfolio ? ExecutionPolicies::current($portfolio) : null;
        if (! $execution || $execution->id !== $this->executionId || ! in_array($this->symbol, app(SymbolUniverse::class)->reads($connection, CarbonImmutable::now()), true)) {
            return;
        }
        $inputs->collect($connection, $connection->user_id, $this->symbol, $this->requestId);
    }
}
