<?php

namespace App\Services\Risk;

use App\Contracts\PortfolioContext;

class ExposureService
{
    public function openPositionCount(PortfolioContext $context): int
    {
        return collect($context->positions())->filter(fn (array $position): bool => (float) ($position['quantity'] ?? 0) > 0
            && ((float) ($position['market_value'] ?? 0) > 0
                || (float) ($position['cost_basis'] ?? 0) > 0
                || (float) ($position['unrealized_pnl'] ?? 0) !== 0.0)
        )->count();
    }

    public function openExposureNotional(PortfolioContext $context): float
    {
        return $context->grossExposureNotional();
    }
}
