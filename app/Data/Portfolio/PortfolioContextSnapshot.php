<?php

namespace App\Data\Portfolio;

use App\Contracts\PortfolioContext;
use Carbon\CarbonImmutable;

final readonly class PortfolioContextSnapshot implements PortfolioContext
{
    /**
     * @param  array<int, array<string, int|float|string|null>>  $positionRows
     */
    public function __construct(
        private string $contextMode,
        private string $identifier,
        private int $routingBrokerAccountId,
        private ?int $sessionId,
        private ?int $pinnedStrategyVersionId,
        private ?int $pinnedUniverseVersionId,
        private float $cashBalance,
        private float $reservedCashBalance,
        private float $portfolioEquity,
        private array $positionRows,
        private float $grossExposure,
        private float $grossExposurePercent,
        private float $correlatedExposurePercent,
        private float $drawdownPercent,
        private float $dailyRealizedLossPercent,
        private float $weeklyRealizedLossPercent,
        private CarbonImmutable $valuedAt,
        private string $hash,
    ) {}

    public function mode(): string
    {
        return $this->contextMode;
    }

    public function contextId(): string
    {
        return $this->identifier;
    }

    public function brokerAccountId(): int
    {
        return $this->routingBrokerAccountId;
    }

    public function paperSessionId(): ?int
    {
        return $this->sessionId;
    }

    public function strategyVersionId(): ?int
    {
        return $this->pinnedStrategyVersionId;
    }

    public function universeVersionId(): ?int
    {
        return $this->pinnedUniverseVersionId;
    }

    public function cash(): float
    {
        return $this->cashBalance;
    }

    public function reservedCash(): float
    {
        return $this->reservedCashBalance;
    }

    public function availableCash(): float
    {
        return max(0.0, $this->cashBalance - $this->reservedCashBalance);
    }

    public function equity(): float
    {
        return $this->portfolioEquity;
    }

    public function positions(): array
    {
        return $this->positionRows;
    }

    public function positionQuantity(int $assetId): float
    {
        foreach ($this->positionRows as $position) {
            if ((int) $position['asset_id'] === $assetId) {
                return (float) $position['quantity'];
            }
        }

        return 0.0;
    }

    public function grossExposureNotional(): float
    {
        return $this->grossExposure;
    }

    public function grossExposurePct(): float
    {
        return $this->grossExposurePercent;
    }

    public function correlatedExposurePct(): float
    {
        return $this->correlatedExposurePercent;
    }

    public function drawdownPct(): float
    {
        return $this->drawdownPercent;
    }

    public function dailyLossPct(): float
    {
        return $this->dailyRealizedLossPercent;
    }

    public function weeklyLossPct(): float
    {
        return $this->weeklyRealizedLossPercent;
    }

    public function valuationTime(): CarbonImmutable
    {
        return $this->valuedAt;
    }

    public function contentHash(): string
    {
        return $this->hash;
    }

    public function toPayload(): array
    {
        return [
            'schema_version' => '1.0',
            'mode' => $this->contextMode,
            'context_id' => $this->identifier,
            'broker_account_id' => $this->routingBrokerAccountId,
            'broker_account_role' => 'execution_routing_only',
            'paper_session_id' => $this->sessionId,
            'strategy_version_id' => $this->pinnedStrategyVersionId,
            'universe_version_id' => $this->pinnedUniverseVersionId,
            'cash' => $this->cashBalance,
            'reserved_cash' => $this->reservedCashBalance,
            'equity' => $this->portfolioEquity,
            'positions' => $this->positionRows,
            'gross_exposure_notional' => $this->grossExposure,
            'gross_exposure_pct' => $this->grossExposurePercent,
            'correlated_exposure_pct' => $this->correlatedExposurePercent,
            'drawdown_pct' => $this->drawdownPercent,
            'realized_loss_windows' => [
                'daily_pct' => $this->dailyRealizedLossPercent,
                'weekly_pct' => $this->weeklyRealizedLossPercent,
            ],
            'valuation_at' => $this->valuedAt->toIso8601String(),
            'context_hash' => $this->hash,
        ];
    }
}
