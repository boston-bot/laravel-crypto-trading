<?php

namespace App\Contracts;

use Carbon\CarbonImmutable;

interface PortfolioContext
{
    public function mode(): string;

    public function contextId(): string;

    public function brokerAccountId(): int;

    public function paperSessionId(): ?int;

    public function strategyVersionId(): ?int;

    public function universeVersionId(): ?int;

    public function cash(): float;

    public function reservedCash(): float;

    public function availableCash(): float;

    public function equity(): float;

    /** @return array<int, array<string, int|float|string|null>> */
    public function positions(): array;

    public function positionQuantity(int $assetId): float;

    public function grossExposureNotional(): float;

    public function grossExposurePct(): float;

    public function correlatedExposurePct(): float;

    public function drawdownPct(): float;

    public function dailyLossPct(): float;

    public function weeklyLossPct(): float;

    public function valuationTime(): CarbonImmutable;

    public function contentHash(): string;

    /** @return array<string, mixed> */
    public function toPayload(): array;
}
