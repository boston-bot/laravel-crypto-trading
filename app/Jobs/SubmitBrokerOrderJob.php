<?php

namespace App\Jobs;

use App\Services\Execution\TradeExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SubmitBrokerOrderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tradeDecisionId,
    ) {
    }

    public function handle(TradeExecutionService $tradeExecutionService): void
    {
        $tradeExecutionService->submitDecision($this->tradeDecisionId);
    }
}

