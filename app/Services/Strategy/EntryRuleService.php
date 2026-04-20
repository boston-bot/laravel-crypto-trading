<?php

namespace App\Services\Strategy;

class EntryRuleService
{
    /**
     * @param array<string, mixed> $ta
     * @param array<string, mixed> $marketRank
     */
    public function shouldEnter(array $ta, array $marketRank): bool
    {
        $probability = (float) ($marketRank['probability_tier']['probability'] ?? 0.0);
        $regimeState = (string) ($ta['regime_state'] ?? $marketRank['regime']['state'] ?? 'neutral');
        $topRankLimit = (int) config('trading.entry.max_universe_rank', 3);

        return ($ta['feature_ready'] ?? false) === true
            && ($ta['trend'] ?? null) === 'uptrend'
            && ($ta['momentum'] ?? 0.0) > -0.05
            && ($ta['rsi'] ?? 100) < (float) config('trading.entry.max_rsi', 72)
            && ($ta['extension_pct'] ?? 1.0) <= (float) config('trading.entry.max_extension_pct', 0.03)
            && ($ta['atr_pct'] ?? 1.0) <= (float) config('trading.entry.max_atr_pct', 0.09)
            && in_array($regimeState, ['risk_on', 'neutral'], true)
            && ($marketRank['entry_eligible'] ?? false) === true
            && ($marketRank['universe_rank'] ?? 999) <= $topRankLimit
            && $probability >= (float) config('trading.entry.min_probability', 0.52);
    }
}
