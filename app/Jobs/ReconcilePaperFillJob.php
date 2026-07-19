<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcilePaperFillJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $brokerOrderId,
    ) {}

    public function handle(): void
    {
        ReconcileBrokerFillJob::dispatchSync($this->brokerOrderId);
    }
}
