<?php

namespace App\Jobs;

use App\Models\PaperTrading\DecisionRun;
use App\Services\PaperTrading\DecisionRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PaperDecisionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $runId, public string $scope)
    {
        $this->onConnection('mcp_research');
        $this->onQueue('research');
    }

    public function handle(DecisionRunner $runner): void
    {
        if (DecisionRun::whereKey($this->runId)->where('scope', $this->scope)->exists()) {
            $runner->process($this->runId);
        }
    }
}
