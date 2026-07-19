<?php

namespace App\Jobs;

use App\Models\OperatorAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncOperationsDataJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $operatorActionId) {}

    public function handle(): void
    {
        $action = OperatorAction::query()->findOrFail($this->operatorActionId);
        $action->update(['status' => 'running', 'started_at' => now()]);
        try {
            app()->call([new SyncCoinbaseMarketDataJob(timeframe: '1h'), 'handle']);
            $action->update(['status' => 'completed', 'completed_at' => now(), 'result_json' => ['message' => 'Coinbase quotes and 1-hour candles were synchronized.']]);
        } catch (Throwable $exception) {
            $action->update(['status' => 'failed', 'completed_at' => now(), 'last_error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
