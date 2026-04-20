<?php

namespace App\Services\Risk;

use App\Models\BrokerAccount;

class ExposureService
{
    public function openPositionCount(BrokerAccount $account): int
    {
        return $account->positions()
            ->where('quantity', '>', 0)
            ->where(function ($query): void {
                $query->where('market_value', '>', 0)
                    ->orWhere('avg_cost', '>', 0)
                    ->orWhere('unrealized_pnl', '!=', 0);
            })
            ->count();
    }

    public function openExposureNotional(BrokerAccount $account): float
    {
        return (float) $account->positions()->sum('market_value');
    }
}
