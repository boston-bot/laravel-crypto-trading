<?php

namespace App\Services\Risk;

use App\Models\BrokerAccount;
use App\Models\DailyPortfolioSnapshot;

class DrawdownService
{
    public function drawdownPct(BrokerAccount $account): float
    {
        $peak = DailyPortfolioSnapshot::query()
            ->where('broker_account_id', $account->id)
            ->max('equity');

        if (! $peak || $peak <= 0) {
            return 0.0;
        }

        $current = (float) $account->equity;

        return max(0.0, (($peak - $current) / $peak) * 100);
    }

    public function dailyLossPct(BrokerAccount $account): float
    {
        $snapshot = DailyPortfolioSnapshot::query()
            ->where('broker_account_id', $account->id)
            ->whereDate('snapshot_date', today())
            ->first();

        if (! $snapshot || (float) $snapshot->equity <= 0) {
            return 0.0;
        }

        $loss = max(0.0, -1 * (float) $snapshot->realized_pnl);

        return ($loss / (float) $snapshot->equity) * 100;
    }

    public function weeklyLossPct(BrokerAccount $account): float
    {
        $equity = DailyPortfolioSnapshot::query()
            ->where('broker_account_id', $account->id)
            ->whereDate('snapshot_date', '>=', now()->startOfWeek()->toDateString())
            ->sum('equity');

        if ($equity <= 0) {
            return 0.0;
        }

        $realizedPnl = DailyPortfolioSnapshot::query()
            ->where('broker_account_id', $account->id)
            ->whereDate('snapshot_date', '>=', now()->startOfWeek()->toDateString())
            ->sum('realized_pnl');

        $loss = max(0.0, -1 * (float) $realizedPnl);

        return ($loss / (float) $equity) * 100;
    }
}
