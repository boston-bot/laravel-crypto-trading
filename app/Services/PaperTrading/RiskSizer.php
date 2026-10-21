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

    public function calculate(Decimal $equity, Decimal $cash, Decimal $bound, Decimal $stop, array $settings, ?Decimal $notionalCap = null, int $scale = 0): array
    {
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
}
