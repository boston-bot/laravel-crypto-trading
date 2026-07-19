<?php

namespace App\Jobs;

use App\Services\MarketData\SentimentIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncFearGreedSentimentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $limit = 30) {}

    public function handle(SentimentIngestionService $sentiment): void
    {
        $sentiment->refresh($this->limit);
    }
}
