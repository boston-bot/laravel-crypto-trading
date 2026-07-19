<?php

namespace App\Services\PaperTrading;

use App\Enums\OrderSide;
use App\Models\BrokerOrder;
use App\Models\PaperPosition;
use App\Models\TradeAttribution;
use App\Models\TradeDecision;

class PaperAttributionService
{
    public function record(
        TradeDecision $decision,
        BrokerOrder $order,
        ?PaperPosition $position = null,
        ?float $realizedPnl = null,
    ): TradeAttribution {
        $expectedProbability = (float) ($decision->signal_context_json['scoring']['probability'] ?? $decision->confidence ?? 0.0);
        $expectedExpectancy = (float) ($decision->signal_context_json['scoring']['expected_expectancy'] ?? 0.0);
        $fillPrice = (float) ($order->avg_fill_price ?? 0.0);
        $referencePrice = (float) ($order->requested_price ?? 0.0);
        $realizedReturnPct = null;

        if ($referencePrice > 0.0 && $fillPrice > 0.0) {
            $sign = $decision->side === OrderSide::SELL ? 1 : -1;
            $realizedReturnPct = (($fillPrice - $referencePrice) / $referencePrice) * 100 * $sign;
        }

        return TradeAttribution::query()->create([
            'trade_decision_id' => $decision->id,
            'broker_order_id' => $order->id,
            'paper_session_id' => $order->paper_session_id,
            'asset_id' => $decision->asset_id,
            'expected_probability' => $expectedProbability,
            'expected_expectancy' => $expectedExpectancy,
            'realized_return_pct' => $realizedReturnPct,
            'realized_pnl' => $realizedPnl,
            'mae_pct' => null,
            'mfe_pct' => null,
            'hold_hours' => $position?->opened_at ? (int) $position->opened_at->diffInHours(now()) : null,
            'attributed_at' => now(),
            'attribution_json' => [
                'mode' => 'paper',
                'status' => $order->status?->value,
                'quantity' => (float) ($order->filled_quantity ?? 0.0),
                'fill_price' => $fillPrice,
                'reference_price' => $referencePrice,
            ],
        ]);
    }
}
