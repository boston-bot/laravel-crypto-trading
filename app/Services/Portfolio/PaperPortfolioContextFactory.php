<?php

namespace App\Services\Portfolio;

use App\Contracts\PortfolioContext;
use App\Data\Portfolio\PortfolioContextSnapshot;
use App\Models\BrokerAccount;
use App\Models\PaperLedgerEntry;
use App\Models\PaperPortfolioSnapshot;
use App\Models\PaperSession;
use App\Models\TradeAttribution;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

class PaperPortfolioContextFactory
{
    public function create(
        BrokerAccount $account,
        ?int $strategyVersionId = null,
        ?int $universeVersionId = null,
    ): PortfolioContext {
        $session = PaperSession::query()
            ->where('broker_account_id', $account->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if ($session === null || $session->strategy_version_id === null || $session->universe_version_id === null) {
            throw new RuntimeException('Paper evaluation requires a fully pinned active paper session.');
        }
        if (($strategyVersionId !== null && $strategyVersionId !== $session->strategy_version_id)
            || ($universeVersionId !== null && $universeVersionId !== $session->universe_version_id)) {
            throw new RuntimeException('Paper evaluation context does not match the session strategy and universe pins.');
        }

        $positions = $session->positions()
            ->with('asset:id,symbol')
            ->where('quantity', '>', 0)
            ->orderBy('asset_id')
            ->get()
            ->map(fn ($position): array => [
                'asset_id' => (int) $position->asset_id,
                'symbol' => (string) $position->asset?->symbol,
                'quantity' => (float) $position->quantity,
                'cost_basis' => (float) $position->cost_basis,
                'market_value' => (float) $position->market_value,
                'unrealized_pnl' => (float) $position->unrealized_pnl,
            ])
            ->all();
        $cash = (float) PaperLedgerEntry::query()->where('paper_session_id', $session->id)->sum('cash_delta');
        $grossExposure = (float) collect($positions)->sum('market_value');
        $latest = PaperPortfolioSnapshot::query()->where('paper_session_id', $session->id)->latest('snapshot_time')->first();
        $equity = $latest !== null ? (float) $latest->equity : $cash + $grossExposure;
        $peak = (float) PaperPortfolioSnapshot::query()->where('paper_session_id', $session->id)->max('equity');
        $drawdown = $peak > 0 ? max(0.0, (($peak - $equity) / $peak) * 100) : 0.0;
        $valuationAt = $latest?->snapshot_time
            ?? PaperLedgerEntry::query()->where('paper_session_id', $session->id)->latest('occurred_at')->value('occurred_at')
            ?? $session->started_at;
        $dailyLoss = $this->realizedLossPct($session, now()->startOfDay(), $equity);
        $weeklyLoss = $this->realizedLossPct($session, now()->startOfWeek(), $equity);

        return $this->snapshot(
            session: $session,
            cash: $cash,
            equity: $equity,
            positions: $positions,
            grossExposure: $grossExposure,
            drawdown: $drawdown,
            dailyLoss: $dailyLoss,
            weeklyLoss: $weeklyLoss,
            valuationAt: CarbonImmutable::parse($valuationAt)->utc(),
        );
    }

    /** @param array<int, array<string, int|float|string|null>> $positions */
    private function snapshot(
        PaperSession $session,
        float $cash,
        float $equity,
        array $positions,
        float $grossExposure,
        float $drawdown,
        float $dailyLoss,
        float $weeklyLoss,
        CarbonImmutable $valuationAt,
    ): PortfolioContext {
        $values = [
            'schema_version' => '1.0',
            'mode' => 'paper',
            'context_id' => 'paper-session:'.$session->id,
            'broker_account_id' => (int) $session->broker_account_id,
            'broker_account_role' => 'execution_routing_only',
            'paper_session_id' => $session->id,
            'strategy_version_id' => $session->strategy_version_id,
            'universe_version_id' => $session->universe_version_id,
            'cash' => round($cash, 8),
            'reserved_cash' => round((float) $session->reserved_cash, 8),
            'equity' => round($equity, 8),
            'positions' => $positions,
            'gross_exposure_notional' => round($grossExposure, 8),
            'gross_exposure_pct' => $equity > 0 ? round(($grossExposure / $equity) * 100, 4) : 0.0,
            'correlated_exposure_pct' => 0.0,
            'drawdown_pct' => round($drawdown, 4),
            'realized_loss_windows' => ['daily_pct' => round($dailyLoss, 4), 'weekly_pct' => round($weeklyLoss, 4)],
            'valuation_at' => $valuationAt->toIso8601String(),
        ];
        $hash = $this->hash($values);

        return new PortfolioContextSnapshot(
            contextMode: 'paper',
            identifier: $values['context_id'],
            routingBrokerAccountId: $values['broker_account_id'],
            sessionId: $session->id,
            pinnedStrategyVersionId: $session->strategy_version_id,
            pinnedUniverseVersionId: $session->universe_version_id,
            cashBalance: $values['cash'],
            reservedCashBalance: $values['reserved_cash'],
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

    private function realizedLossPct(PaperSession $session, CarbonInterface $from, float $equity): float
    {
        if ($equity <= 0) {
            return 0.0;
        }
        $pnl = (float) TradeAttribution::query()
            ->where('paper_session_id', $session->id)
            ->where('attributed_at', '>=', $from)
            ->sum('realized_pnl');

        return max(0.0, -$pnl) / $equity * 100;
    }

    /** @param array<string, mixed> $values */
    private function hash(array $values): string
    {
        $this->sortRecursively($values);

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
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
