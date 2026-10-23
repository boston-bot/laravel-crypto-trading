<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;

/** Shared adverse bid/ask paper fill math for forward simulation and retrospective research. */
final class PaperFillPrice
{
    public static function calculate(string $side, string $reference, string $slippageBps, int $scale = 8): Decimal
    {
        $buy = $side === 'BUY';
        $multiplier = Decimal::from('1')->plus(Decimal::from($slippageBps)->dividedBy(Decimal::from('10000'), 12)->multipliedBy(Decimal::from($buy ? '1' : '-1')));

        return Decimal::from($reference)->multipliedBy($multiplier)->rounded($scale, $buy ? RoundingMode::CEILING : RoundingMode::FLOOR);
    }
}
