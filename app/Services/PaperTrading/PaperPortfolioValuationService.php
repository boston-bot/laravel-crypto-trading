<?php

namespace App\Services\PaperTrading;

use App\Models\BrokerAccount;
use App\Models\MarketQuote;
use App\Models\PaperLedgerEntry;
use App\Models\PaperPortfolioSnapshot;
use App\Models\PaperPosition;
use App\Models\PaperSession;

class PaperPortfolioValuationService
{
    public function snapshot(BrokerAccount $account): ?PaperPortfolioSnapshot
    {
        $session = PaperSession::query()->where('broker_account_id', $account->id)->where('status', 'active')->latest('id')->first();
        if ($session === null) {
            return null;
        }
        $positionQuery = PaperPosition::query()
            ->where('broker_account_id', $account->id)
            ->where('paper_session_id', $session->id)
            ->where('quantity', '>', 0);
        $positions = $positionQuery->get();

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

        $aggregateQuery = PaperPosition::query()
            ->where('broker_account_id', $account->id)
            ->selectRaw('COALESCE(SUM(market_value), 0) as invested')
            ->selectRaw('COALESCE(SUM(realized_pnl), 0) as realized')
            ->selectRaw('COALESCE(SUM(unrealized_pnl), 0) as unrealized');
        $aggregateQuery->where('paper_session_id', $session->id);
        $aggregate = $aggregateQuery->first();

        $invested = (float) ($aggregate->invested ?? 0.0);
        $realized = (float) ($aggregate->realized ?? 0.0);
        $unrealized = (float) ($aggregate->unrealized ?? 0.0);

        $cash = max(0.0, (float) PaperLedgerEntry::query()->where('paper_session_id', $session->id)->sum('cash_delta'));
        $equity = max(0.0, $cash + $invested);
        $grossExposurePct = $equity > 0 ? ($invested / $equity) * 100 : 0.0;

        $snapshotTime = now()->startOfMinute();

        $peakEquity = max(
            $equity,
            (float) PaperPortfolioSnapshot::query()
                ->where('paper_session_id', $session->id)
                ->max('equity')
        );

        $drawdownPct = $peakEquity > 0
            ? max(0.0, (($peakEquity - $equity) / $peakEquity) * 100)
            : 0.0;

        $snapshot = PaperPortfolioSnapshot::query()->updateOrCreate(
            [
                'broker_account_id' => $account->id,
                'paper_session_id' => $session?->id,
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
                    'paper_session_id' => $session?->id,
                ],
            ]
        );
        $session->update(['valuation_at' => $snapshotTime]);

        return $snapshot;
    }
}
