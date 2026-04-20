<?php

namespace App\Services\PaperTrading;

use App\Models\BrokerAccount;
use App\Models\MarketQuote;
use App\Models\PaperPortfolioSnapshot;
use App\Models\PaperPosition;

class PaperPortfolioValuationService
{
    public function snapshot(BrokerAccount $account): PaperPortfolioSnapshot
    {
        $positions = PaperPosition::query()
            ->where('broker_account_id', $account->id)
            ->where('quantity', '>', 0)
            ->get();

        foreach ($positions as $position) {
            $quote = MarketQuote::query()
                ->where('asset_id', $position->asset_id)
                ->latest('snapshot_time')
                ->first();

            $marketPrice = (float) ($quote?->mid_price ?? $quote?->last_price ?? $position->market_price ?? 0.0);
            if ($marketPrice <= 0) {
                continue;
            }

            $marketValue = $marketPrice * (float) $position->quantity;
            $position->update([
                'market_price' => $marketPrice,
                'market_value' => $marketValue,
                'unrealized_pnl' => $marketValue - (float) $position->cost_basis,
                'updated_snapshot_at' => now(),
            ]);
        }

        $aggregate = PaperPosition::query()
            ->where('broker_account_id', $account->id)
            ->selectRaw('COALESCE(SUM(market_value), 0) as invested')
            ->selectRaw('COALESCE(SUM(realized_pnl), 0) as realized')
            ->selectRaw('COALESCE(SUM(unrealized_pnl), 0) as unrealized')
            ->first();

        $invested = (float) ($aggregate->invested ?? 0.0);
        $realized = (float) ($aggregate->realized ?? 0.0);
        $unrealized = (float) ($aggregate->unrealized ?? 0.0);

        $cash = max(0.0, (float) $account->cash_balance + $realized);
        $equity = max(0.0, $cash + $invested);
        $grossExposurePct = $equity > 0 ? ($invested / $equity) * 100 : 0.0;

        $snapshotTime = now()->startOfMinute();

        $peakEquity = max(
            $equity,
            (float) PaperPortfolioSnapshot::query()
                ->where('broker_account_id', $account->id)
                ->max('equity')
        );

        $drawdownPct = $peakEquity > 0
            ? max(0.0, (($peakEquity - $equity) / $peakEquity) * 100)
            : 0.0;

        return PaperPortfolioSnapshot::query()->updateOrCreate(
            [
                'broker_account_id' => $account->id,
                'snapshot_time' => $snapshotTime,
            ],
            [
                'equity' => $equity,
                'cash' => $cash,
                'invested_value' => $invested,
                'realized_pnl' => $realized,
                'unrealized_pnl' => $unrealized,
                'gross_exposure_pct' => $grossExposurePct,
                'heat_score' => min(100.0, $grossExposurePct),
                'drawdown_pct' => $drawdownPct,
                'metadata_json' => [
                    'positions' => $positions->count(),
                    'mode' => 'paper',
                ],
            ]
        );
    }
}
