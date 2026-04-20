<?php

namespace App\Services\Portfolio;

use App\Models\Asset;
use App\Models\BrokerAccount;

class CorrelationService
{
    /**
     * @return float Estimated correlation coefficient (0-1)
     */
    public function estimateBySymbol(string $symbolA, string $symbolB): float
    {
        $a = strtoupper($symbolA);
        $b = strtoupper($symbolB);

        if ($a === $b) {
            return 1.0;
        }

        $majors = ['BTC', 'ETH', 'SOL', 'AVAX', 'LINK'];
        $betaGroup = ['DOGE', 'SHIB', 'PEPE'];

        if (in_array($a, $majors, true) && in_array($b, $majors, true)) {
            return 0.78;
        }

        if (in_array($a, $betaGroup, true) && in_array($b, $betaGroup, true)) {
            return 0.82;
        }

        if ((in_array($a, $majors, true) && in_array($b, $betaGroup, true))
            || (in_array($b, $majors, true) && in_array($a, $betaGroup, true))) {
            return 0.62;
        }

        return 0.45;
    }

    public function correlatedExposurePct(BrokerAccount $account, Asset $candidateAsset): float
    {
        $equity = max(1.0, (float) $account->equity);

        $openPositions = $account->positions()
            ->with('asset')
            ->where('quantity', '>', 0)
            ->get();

        $weightedExposure = 0.0;
        foreach ($openPositions as $position) {
            $symbol = (string) ($position->asset?->symbol ?? '');
            if ($symbol === '') {
                continue;
            }

            $corr = $this->estimateBySymbol($candidateAsset->symbol, $symbol);
            $weightedExposure += ((float) ($position->market_value ?? 0.0)) * $corr;
        }

        return ($weightedExposure / $equity) * 100;
    }
}
