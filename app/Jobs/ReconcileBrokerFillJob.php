<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\BrokerOrder;
use App\Services\Execution\TradeExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileBrokerFillJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $brokerOrderId = null,
    ) {}

    public function handle(TradeExecutionService $tradeExecutionService): void
    {
        if ($this->brokerOrderId !== null) {
            $order = BrokerOrder::query()->find($this->brokerOrderId);
            if ($order !== null) {
                $tradeExecutionService->reconcileOrder($order);
            }

            return;
        }

        BrokerOrder::query()
            ->whereIn('status', [
                OrderStatus::SUBMITTED->value,
                OrderStatus::PARTIALLY_FILLED->value,
                OrderStatus::RECONCILIATION_REQUIRED->value,
            ])
            ->chunkById(100, function ($orders) use ($tradeExecutionService): void {
                foreach ($orders as $order) {
                    $tradeExecutionService->reconcileOrder($order);
                }
            });
    }
}
