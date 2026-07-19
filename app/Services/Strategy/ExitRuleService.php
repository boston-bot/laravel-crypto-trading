<?php

namespace App\Services\Strategy;

class ExitRuleService
{
    /**
     * @param  array<string, mixed>  $ta
     */
    public function shouldExit(array $ta, array $marketRank = []): bool
    {
        $regimeState = (string) ($ta['regime_state'] ?? $marketRank['regime']['state'] ?? 'neutral');

        return $regimeState === 'risk_off'
            || ($ta['trend'] ?? 'uptrend') === 'downtrend'
            || ($ta['momentum'] ?? 0) < -0.15
            || ($ta['rsi'] ?? 0) > (float) config('trading.exit.max_rsi', 78)
            || ($marketRank['exit_deterioration'] ?? false) === true;
    }
}
