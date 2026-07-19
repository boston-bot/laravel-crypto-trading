<?php

namespace App\Services\Audit;

use App\Models\TradeDecision;
use Illuminate\Support\Facades\Log;

class DecisionJournalService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(TradeDecision $decision, array $context = []): void
    {
        Log::channel(config('logging.default'))->info('trade_decision_journal', [
            'decision_id' => $decision->id,
            'strategy_run_id' => $decision->strategy_run_id,
            'asset_id' => $decision->asset_id,
            'status' => $decision->status?->value ?? $decision->status,
            'decision' => $decision->decision?->value ?? $decision->decision,
            'context' => $context,
        ]);
    }
}
