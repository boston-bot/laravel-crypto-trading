<?php

namespace App\Services\Portfolio;

use App\Contracts\PortfolioContext;
use App\Data\Portfolio\PortfolioContextSnapshot;
use App\Models\BrokerAccount;
use App\Models\DailyPortfolioSnapshot;
use Carbon\CarbonImmutable;
use RuntimeException;

class LivePortfolioContextFactory
{
    public function create(
        BrokerAccount $account,
        ?int $strategyVersionId = null,
        ?int $universeVersionId = null,
    ): PortfolioContext {
        if ($account->snapshot_at === null) {
            throw new RuntimeException('Live portfolio context requires a broker account snapshot.');
        }

        $positions = $account->positions()
            ->with('asset:id,symbol')
            ->where('quantity', '>', 0)
            ->orderBy('asset_id')
            ->get()
            ->map(fn ($position): array => [
                'asset_id' => (int) $position->asset_id,
                'symbol' => (string) $position->asset?->symbol,
                'quantity' => (float) $position->quantity,
                'cost_basis' => (float) $position->avg_cost * (float) $position->quantity,
                'market_value' => (float) $position->market_value,
                'unrealized_pnl' => (float) $position->unrealized_pnl,
            ])
            ->all();
        $equity = (float) $account->equity;
        $grossExposure = (float) collect($positions)->sum('market_value');
        $peak = (float) DailyPortfolioSnapshot::query()->where('broker_account_id', $account->id)->max('equity');
        $drawdown = $peak > 0 ? max(0.0, (($peak - $equity) / $peak) * 100) : 0.0;
        $dailyLoss = $this->lossPct($account, now()->startOfDay(), $equity);
        $weeklyLoss = $this->lossPct($account, now()->startOfWeek(), $equity);
        $valuationAt = CarbonImmutable::instance($account->snapshot_at)->utc();
        $values = [
            'schema_version' => '1.0',
            'mode' => 'live',
            'context_id' => 'live-account:'.$account->id.':'.$valuationAt->format('Ymd\THis\Z'),
            'broker_account_id' => $account->id,
            'broker_account_role' => 'execution_routing_only',
            'paper_session_id' => null,
            'strategy_version_id' => $strategyVersionId,
            'universe_version_id' => $universeVersionId,
            'cash' => round((float) $account->buying_power, 8),
            'reserved_cash' => 0.0,
            'equity' => round($equity, 8),
            'positions' => $positions,
            'gross_exposure_notional' => round($grossExposure, 8),
            'gross_exposure_pct' => $equity > 0 ? round(($grossExposure / $equity) * 100, 4) : 0.0,
            'correlated_exposure_pct' => 0.0,
            'drawdown_pct' => round($drawdown, 4),
            'realized_loss_windows' => ['daily_pct' => round($dailyLoss, 4), 'weekly_pct' => round($weeklyLoss, 4)],
            'valuation_at' => $valuationAt->toIso8601String(),
        ];
        $hashValues = $values;
        $this->sortRecursively($hashValues);
        $hash = hash('sha256', json_encode($hashValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return new PortfolioContextSnapshot(
            contextMode: 'live',
            identifier: $values['context_id'],
            routingBrokerAccountId: $account->id,
            sessionId: null,
            pinnedStrategyVersionId: $strategyVersionId,
            pinnedUniverseVersionId: $universeVersionId,
            cashBalance: $values['cash'],
            reservedCashBalance: 0.0,
            portfolioEquity: $values['equity'],
            positionRows: $positions,
            grossExposure: $values['gross_exposure_notional'],
            grossExposurePercent: $values['gross_exposure_pct'],
            correlatedExposurePercent: 0.0,
            drawdownPercent: $values['drawdown_pct'],
            dailyRealizedLossPercent: $values['realized_loss_windows']['daily_pct'],
            weeklyRealizedLossPercent: $values['realized_loss_windows']['weekly_pct'],
            valuedAt: $valuationAt,
            hash: $hash,
        );
    }

    private function lossPct(BrokerAccount $account, \DateTimeInterface $from, float $equity): float
    {
        if ($equity <= 0) {
            return 0.0;
        }
        $pnl = (float) DailyPortfolioSnapshot::query()
            ->where('broker_account_id', $account->id)
            ->whereDate('snapshot_date', '>=', $from->format('Y-m-d'))
            ->sum('realized_pnl');

        return max(0.0, -$pnl) / $equity * 100;
    }

    /** @param array<mixed> $values */
    private function sortRecursively(array &$values): void
    {
        if (! array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as &$value) {
            if (is_array($value)) {
                $this->sortRecursively($value);
            }
        }
    }
}
