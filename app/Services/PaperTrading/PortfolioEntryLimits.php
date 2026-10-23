<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;

/** Pure admission gates. Caller supplies a verified day-opening baseline and marked portfolio equity. */
final class PortfolioEntryLimits
{
    public static function reason(?string $equity, ?string $openingEquity, int $occupied, array $policy, bool $lossLatched = false): ?string
    {
        if ($lossLatched) {
            return 'daily_loss_pause';
        }
        if ($equity === null || $openingEquity === null || Decimal::from($openingEquity)->compareTo(Decimal::from('0')) <= 0) {
            return 'portfolio_valuation_unavailable';
        }
        $opening = Decimal::from($openingEquity);
        $loss = $opening->minus(Decimal::from($equity))->multipliedBy(Decimal::from('100'));
        if ($loss->compareTo($opening->multipliedBy(Decimal::from($policy['daily_loss_pause_pct']))) >= 0) {
            return 'daily_loss_pause';
        }
        if ($occupied >= $policy['max_open_positions']) {
            return 'maximum_open_positions';
        }

        return null;
    }
}
