<?php

namespace App\Jobs;

use App\Models\RiskEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendRiskAlertJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $eventType,
        public readonly string $message,
        public readonly array $context = [],
        public readonly ?int $assetId = null,
        public readonly ?int $brokerOrderId = null,
        public readonly ?int $tradeDecisionId = null,
    ) {}

    public function handle(): void
    {
        RiskEvent::query()->create([
            'severity' => $this->severity,
            'event_type' => $this->eventType,
            'asset_id' => $this->assetId,
            'broker_order_id' => $this->brokerOrderId,
            'trade_decision_id' => $this->tradeDecisionId,
            'message' => $this->message,
            'context_json' => $this->context,
            'triggered_at' => now(),
        ]);

        Log::warning('risk_alert', [
            'severity' => $this->severity,
            'event_type' => $this->eventType,
            'message' => $this->message,
            'context' => $this->context,
        ]);
    }
}
