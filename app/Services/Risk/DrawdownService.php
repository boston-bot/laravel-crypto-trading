<?php

namespace App\Services\Risk;

use App\Contracts\PortfolioContext;

class DrawdownService
{
    public function drawdownPct(PortfolioContext $context): float
    {
        return $context->drawdownPct();
    }

    public function dailyLossPct(PortfolioContext $context): float
    {
        return $context->dailyLossPct();
    }

    public function weeklyLossPct(PortfolioContext $context): float
    {
        return $context->weeklyLossPct();
    }
}
