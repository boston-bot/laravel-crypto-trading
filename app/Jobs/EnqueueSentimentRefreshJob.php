<?php

namespace App\Jobs;

use App\Models\EngineJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class EnqueueSentimentRefreshJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $asOf = now('UTC')->startOfDay();
        EngineJob::query()->firstOrCreate(
            ['idempotency_key' => hash('sha256', 'sentiment_refresh|'.$asOf->toIso8601String())],
            [
                'id' => (string) Str::uuid(), 'schema_version' => (string) config('research.engine.schema_version', '1.0'),
                'kind' => 'sentiment_refresh', 'as_of' => $asOf,
                'payload_json' => ['url' => (string) config('research.sentiment.url'), 'limit' => 90, 'can_trigger_trade' => false],
                'status' => 'pending', 'max_attempts' => (int) config('research.engine.max_attempts', 3),
            ],
        );
    }
}
