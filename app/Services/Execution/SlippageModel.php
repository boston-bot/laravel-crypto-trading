<?php

namespace App\Services\Execution;

use App\Enums\OrderSide;

class SlippageModel
{
    public function estimateFillPrice(
        float $referencePrice,
        OrderSide $side,
        float $notionalUsd = 0.0,
        float $volatility = 0.03,
        float $spreadBps = 30.0,
        float $liquidityScore = 0.6,
    ): float {
        if ($referencePrice <= 0) {
            return 0.0;
        }

        $slippageBps = $this->estimateSlippageBps($notionalUsd, $volatility, $spreadBps, $liquidityScore);
        $buffer = $slippageBps / 10000;
        $directionalBuffer = $side === OrderSide::BUY ? $buffer : -$buffer;

        return round($referencePrice * (1 + $directionalBuffer), 8);
    }

    public function estimateSlippageBps(
        float $notionalUsd = 0.0,
        float $volatility = 0.03,
        float $spreadBps = 30.0,
        float $liquidityScore = 0.6,
    ): float {
        $sizeImpact = min(40.0, max(0.0, ($notionalUsd / 1000) * 6.5));
        $volatilityImpact = max(0.0, $volatility * 10000 * 0.08);
        $spreadImpact = max(2.0, $spreadBps / 2);
        $liquidityPenalty = max(0.0, (1 - min(1.0, max(0.0, $liquidityScore))) * 20);

        return round($sizeImpact + $volatilityImpact + $spreadImpact + $liquidityPenalty, 4);
    }
}
