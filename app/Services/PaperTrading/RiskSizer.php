<?php

namespace App\Services\PaperTrading;

use App\Services\Broker\Agentic\RobinhoodOrderContract;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;

/**
 * Whole shares by default. Robinhood allows fractional quantities only on regular-hours market orders, so a scale
 * above zero is for that order mode alone: up to 6 decimals with the broker's minimum order value. Every cap rounds down.
 */
final class RiskSizer
{
    public function __construct(private TradingCosts $costs) {}

    public function calculate(Decimal $equity, Decimal $cash, Decimal $bound, Decimal $stop, array $settings, ?Decimal $notionalCap = null, int $scale = 0, ?array $instrument = null): array
    {
        if ($instrument !== null) {
            return $this->instrument($equity, $cash, $bound, $stop, $settings, $instrument, $notionalCap);
        }
        if ($scale < 0 || $scale > RobinhoodOrderContract::FRACTIONAL_MAX_DECIMALS) {
            throw new \InvalidArgumentException('Unsupported quantity scale.');
        }
        $priceRisk = $bound->minus($stop);
        $costReserve = $this->costs->buyReserve($settings['cost_schedule'], Decimal::from('1'));
        $exitSlippage = $stop->multipliedBy(Decimal::from($settings['slippage_bps']))->dividedBy(Decimal::from('10000'), 18, RoundingMode::CEILING);
        $exitFees = $bound->multipliedBy(Decimal::from($settings['cost_schedule']['sec_per_million']))->dividedBy(Decimal::from('1000000'), 18, RoundingMode::CEILING)->plus(Decimal::from('0.01'));
        $risk = $priceRisk->plus($costReserve)->plus($exitSlippage)->plus($exitFees);
        if ($equity->compareTo(Decimal::from('0')) <= 0 || $cash->compareTo(Decimal::from('0')) <= 0 || $stop->compareTo(Decimal::from('0')) <= 0 || $priceRisk->compareTo(Decimal::from('0')) <= 0) {
            return ['quantity' => '0', 'reason' => 'no_positive_risk_or_cash'];
        }
        $budget = $equity->multipliedBy(Decimal::from($settings['risk_per_trade_pct']))->dividedBy(Decimal::from('100'), 18, RoundingMode::DOWN);
        $riskQty = $budget->dividedBy($risk, $scale, RoundingMode::DOWN);
        $positionQty = $equity->multipliedBy(Decimal::from($settings['max_position_pct']))->dividedBy(Decimal::from('100'), 18, RoundingMode::DOWN)->dividedBy($bound, $scale, RoundingMode::DOWN);
        if ($notionalCap !== null) {
            $sleeveQty = $notionalCap->compareTo(Decimal::from('0')) <= 0 ? Decimal::from('0') : $notionalCap->dividedBy($bound, $scale, RoundingMode::DOWN);
            $positionQty = $positionQty->compareTo($sleeveQty) > 0 ? $sleeveQty : $positionQty;
        }
        $costPerShare = $this->costs->buyReserve($settings['cost_schedule'], Decimal::from('1'));
        $cashQty = $cash->dividedBy($bound->plus($costPerShare), $scale, RoundingMode::DOWN);
        $quantity = $riskQty;
        foreach ([$positionQty, $cashQty] as $cap) {
            $quantity = $quantity->compareTo($cap) > 0 ? $cap : $quantity;
        }
        $reason = $quantity->compareTo(Decimal::from('0')) > 0 ? null : ($scale === 0 ? 'whole_share_size_zero' : 'fractional_size_zero');
        if ($reason === null && $scale > 0 && $quantity->multipliedBy($bound)->compareTo(Decimal::from(RobinhoodOrderContract::FRACTIONAL_MIN_NOTIONAL)) < 0) {
            [$quantity, $reason] = [Decimal::from('0'), 'below_fractional_minimum_order_value'];
        }

        return ['quantity' => (string) $quantity, 'risk_budget' => (string) $budget, 'risk_per_share' => (string) $risk,
            'risk_quantity' => (string) $riskQty, 'position_quantity' => (string) $positionQty, 'cash_quantity' => (string) $cashQty,
            'reason' => $reason] + ($scale > 0 ? ['quantity_scale' => $scale] : []);
    }

    /** Exact base-increment sizing with the maximum role fee on both sides; no equity regulatory fee assumptions. */
    private function instrument(Decimal $equity, Decimal $cash, Decimal $bound, Decimal $stop, array $s, array $i, ?Decimal $notionalCap): array
    {
        $zero = Decimal::from('0');
        if (($s['cost_schedule']['kind'] ?? null) !== 'venue_notional_rate_v1' || ($i['asset_class'] ?? null) !== 'crypto') {
            throw new \InvalidArgumentException('Unsupported instrument cost contract.');
        }
        if ($equity->compareTo($zero) <= 0 || $cash->compareTo($zero) <= 0 || $stop->compareTo($zero) <= 0 || $bound->compareTo($stop) <= 0) {
            return ['quantity' => '0', 'reason' => 'no_positive_risk_or_cash'];
        }
        $entryFee = $this->costs->fee($s['cost_schedule'], 'BUY', Decimal::from('1'), $bound);
        $exitSlip = $stop->multipliedBy(Decimal::from($s['slippage_bps']))->dividedBy(Decimal::from('10000'), 18, RoundingMode::CEILING);
        // Use the entry bound for the exit fee reserve: conservative for an initial stop below entry.
        $risk = $bound->minus($stop)->plus($entryFee)->plus($exitSlip)->plus($entryFee);
        $budget = $equity->multipliedBy(Decimal::from($s['risk_per_trade_pct']))->dividedBy(Decimal::from('100'), 18, RoundingMode::DOWN);
        $cap = $equity->multipliedBy(Decimal::from($s['max_position_pct']))->dividedBy(Decimal::from('100'), 18, RoundingMode::DOWN);
        foreach ([$notionalCap, Decimal::from($i['quote_max_size'])] as $candidate) {
            if ($candidate !== null && $candidate->compareTo($cap) < 0) {
                $cap = $candidate;
            }
        }
        if ($cap->compareTo($zero) <= 0) {
            return ['quantity' => '0', 'reason' => 'no_position_capacity'];
        }
        // One rounding quantum per execution prevents sub-unit fee rounding from exceeding a tight cap.
        $riskAvailable = $budget->minus(Decimal::from('0.000000000000000002'));
        $cashAvailable = $cash->minus(Decimal::from('0.000000000000000001'));
        if ($riskAvailable->compareTo($zero) <= 0 || $cashAvailable->compareTo($zero) <= 0) {
            return ['quantity' => '0', 'reason' => 'increment_size_zero'];
        }
        $caps = [$riskAvailable->dividedBy($risk, 18, RoundingMode::DOWN), $cap->dividedBy($bound, 18, RoundingMode::DOWN),
            $cashAvailable->dividedBy($bound->plus($entryFee), 18, RoundingMode::DOWN), Decimal::from($i['base_max_size'])];
        $q = $caps[0];
        foreach ($caps as $candidate) {
            $q = $candidate->compareTo($q) < 0 ? $candidate : $q;
        }
        $q = InstrumentSizing::down($q, $i['base_increment']);
        // Fee rounding happens at actual quantity, not by multiplying a rounded per-unit estimate.
        $total = $q->multipliedBy($bound)->plus($this->costs->fee($s['cost_schedule'], 'BUY', $q, $bound));
        if ($total->compareTo($cash) > 0) {
            $q = $q->minus(Decimal::from($i['base_increment']));
        }
        $reason = match (true) {
            $q->compareTo($zero) <= 0 => 'increment_size_zero',
            $q->compareTo(Decimal::from($i['base_min_size'])) < 0 => 'below_base_minimum',
            $q->multipliedBy($bound)->compareTo(Decimal::from($i['quote_min_size'])) < 0 => 'below_quote_minimum',
            default => null,
        };

        return ['quantity' => $reason === null ? (string) $q : '0', 'risk_budget' => (string) $budget,
            'risk_per_unit' => (string) $risk, 'base_increment' => $i['base_increment'], 'reason' => $reason];
    }
}
