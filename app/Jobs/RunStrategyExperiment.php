<?php

namespace App\Jobs;

use App\Services\PaperTrading\Experiments\StrategyExperiments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class RunStrategyExperiment implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 5;

    public function __construct(public int $experimentId)
    {
        $this->onConnection('mcp_experiments');
        $this->onQueue('experiments');
    }

    public function backoff(): array
    {
        return [60, 120, 180];
    }

    public function handle(StrategyExperiments $runner): void
    {
        try {
            $delay = $runner->work($this->experimentId);
            if ($delay > 0) {
                $this->release($delay);
            }
        } catch (Throwable) {
            // Do not retain credential-bearing transport/SQL exception chains in failed_jobs or logs.
            throw new RuntimeException('Historical experiment worker failed; inspect its saved status and resume if needed.');
        }
    }
}
