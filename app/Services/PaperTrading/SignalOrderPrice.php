<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Services\Broker\Agentic\RobinhoodOrderContract;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;

/**
 * Price bounds and quantity scale for signal paper orders, shared by the handoff and its submission recheck so both
 * compute the same values. Market orders are Robinhood's only fractional order type (regular hours only).
 */
final class SignalOrderPrice
{
    /** A market sell has no price floor: exits never wait for a better price. */
    public const MARKET_SELL_FLOOR = '0.00000001';

    /** Market fills give up price control; new settings may not assume less slippage than this. */
    public const MARKET_SLIPPAGE_FLOOR_BPS = '5';

    /**
     * Limit orders: the quote plus the limit offset, buys capped at the profile's maximum entry. Market buys: the highest
     * acceptable fill, which is the maximum entry plus slippage, so a fill is possible only while the ask is at or below it.
     */
    public static function bound(string $type, string $side, array $quote, ?string $maxEntry, array $settings): Decimal
    {
        $buy = $side === 'BUY';
        if ($type === 'market') {
            if (! $buy) {
                return Decimal::from(self::MARKET_SELL_FLOOR);
            }
            if ($maxEntry === null) {
                throw new DecisionFailure('entry_profile_changed_or_blocked', 'unavailable');
            }

            return PaperFillPrice::calculate('BUY', $maxEntry, $settings['slippage_bps']);
        }
        $reference = Decimal::from($quote[$buy ? 'ask' : 'bid']);
        $offset = Decimal::from($settings['limit_offset_bps'])->dividedBy(Decimal::from('10000'), 12);
        $bound = $reference->multipliedBy(Decimal::from('1')->plus($offset->multipliedBy(Decimal::from($buy ? '1' : '-1'))))
            ->rounded(8, $buy ? RoundingMode::CEILING : RoundingMode::FLOOR);
        if ($buy && $maxEntry !== null && $bound->compareTo(Decimal::from($maxEntry)) > 0) {
            $bound = Decimal::from($maxEntry);
        }

        return $bound;
    }

    /** Fractional quantities need market orders, owner permission and Robinhood's current per-symbol eligibility. */
    public static function scale(string $type, array $settings, array $quote): int
    {
        return $type === 'market' && ($settings['allow_fractional'] ?? false) === true && ($quote['fractional'] ?? null) === true &&
            RobinhoodOrderContract::allowsFractional('market') ? RobinhoodOrderContract::FRACTIONAL_MAX_DECIMALS : 0;
    }
}
