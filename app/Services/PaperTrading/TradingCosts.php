<?php

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/** Reviewed templates become immutable owner-applied settings; each order pins the entire schedule. No network. */
final class TradingCosts
{
    public const VERSION = 'rh_listed_equity_2026q4_v1';

    public static function reviewed(): array
    {
        return ['version' => self::VERSION, 'reviewed_on' => '2026-10-19', 'effective_from' => '2026-10-01', 'effective_through' => '2026-12-31',
            'commission_per_share' => '0', 'sec_per_million' => '20.60', 'sec_waiver_notional' => '500',
            'taf_per_share' => '0', 'taf_waiver_shares' => '50', 'taf_max_per_execution' => '9.79',
            'cat_per_share' => '0.000003', 'cat_minimum_charge' => '0.01',
            'rounding_policy' => 'per_simulated_execution_sec_up_other_half_up_v1',
            'sources' => ['https://robinhood.com/us/en/support/articles/trading-fees-on-robinhood/',
                'https://www.sec.gov/rules-regulations/fee-rate-advisories/2026-2',
                'https://www.finra.org/rules-guidance/rule-filings/sr-finra-2026-021']];
    }

    public static function valid(array $schedule, CarbonImmutable $at): bool
    {
        $date = $at->setTimezone('America/New_York')->toDateString();

        return $schedule === self::reviewed() && $date >= $schedule['effective_from'] && $date <= $schedule['effective_through'];
    }

    /** A displayed-book paper fill is modeled as one execution. Thresholds, caps and cent rounding apply per execution. */
    public function fee(array $schedule, string $side, Decimal $quantity, Decimal $price): Decimal
    {
        if (($schedule['kind'] ?? null) === 'venue_notional_rate_v1') {
            // Resting and immediately matched limit fills can have different roles. Until role-specific
            // fills are verified, reserve the greater rate; maker is not always cheaper than taker.
            $maker = Decimal::from($schedule['maker_rate']);
            $taker = Decimal::from($schedule['taker_rate']);
            $rate = $maker->compareTo($taker) > 0 ? $maker : $taker;

            return $quantity->multipliedBy($price)->multipliedBy($rate)->rounded(18, RoundingMode::CEILING);
        }
        $zero = Decimal::from('0');
        $notional = $quantity->multipliedBy($price);
        $commission = $quantity->multipliedBy(Decimal::from($schedule['commission_per_share']));
        $sec = $taf = $zero;
        if ($side === 'SELL') {
            if ($notional->compareTo(Decimal::from($schedule['sec_waiver_notional'])) > 0) {
                $sec = $notional->multipliedBy(Decimal::from($schedule['sec_per_million']))->dividedBy(Decimal::from('1000000'), 2, RoundingMode::CEILING);
            }
            if ($quantity->compareTo(Decimal::from($schedule['taf_waiver_shares'])) > 0) {
                $taf = $quantity->multipliedBy(Decimal::from($schedule['taf_per_share']));
                $cap = Decimal::from($schedule['taf_max_per_execution']);
                $taf = ($taf->compareTo($cap) > 0 ? $cap : $taf)->rounded(2, RoundingMode::HALF_UP);
            }
        }
        $cat = $quantity->multipliedBy(Decimal::from($schedule['cat_per_share']));
        $cat = $cat->compareTo(Decimal::from($schedule['cat_minimum_charge'])) < 0 ? $zero : $cat->rounded(2, RoundingMode::HALF_UP);

        return $commission->plus($sec)->plus($taf)->plus($cat)->rounded(2, RoundingMode::HALF_UP);
    }

    /** Upper bound for a buy reservation, independent of later partial execution boundaries. */
    public function buyReserve(array $schedule, Decimal $quantity): Decimal
    {
        if (($schedule['kind'] ?? null) === 'venue_notional_rate_v1') {
            throw new \InvalidArgumentException('Notional fees require a price; use the instrument-aware sizer.');
        }

        return $quantity->multipliedBy(Decimal::from($schedule['commission_per_share'])->plus(Decimal::from($schedule['cat_per_share'])))
            ->plus($quantity->multipliedBy(Decimal::from('0.005')))->rounded(18, RoundingMode::CEILING);
    }
}
