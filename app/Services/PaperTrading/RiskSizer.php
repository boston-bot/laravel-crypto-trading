<?php

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;

/** Whole-share sizing until fractional LIMIT support is verified. Every cap rounds down. */
final class RiskSizer
{
    public function __construct(private TradingCosts $costs) {}

    public function calculate(Decimal $equity, Decimal $cash, Decimal $bound, Decimal $stop, array $settings, ?Decimal $notionalCap = null): array
    {
        $priceRisk = $bound->minus($stop);
        $costReserve = $this->costs->buyReserve($settings['cost_schedule'], Decimal::from('1'));
        $exitSlippage = $stop->multipliedBy(Decimal::from($settings['slippage_bps']))->dividedBy(Decimal::from('10000'), 18, RoundingMode::CEILING);
        $exitFees = $bound->multipliedBy(Decimal::from($settings['cost_schedule']['sec_per_million']))->dividedBy(Decimal::from('1000000'), 18, RoundingMode::CEILING)->plus(Decimal::from('0.01'));
        $risk = $priceRisk->plus($costReserve)->plus($exitSlippage)->plus($exitFees);
        if ($equity->compareTo(Decimal::from('0')) <= 0 || $cash->compareTo(Decimal::from('0')) <= 0 || $stop->compareTo(Decimal::from('0')) <= 0 || $priceRisk->compareTo(Decimal::from('0')) <= 0) {
            return ['quantity' => '0', 'reason' => 'no_positive_risk_or_cash'];
        }
        $budget = $equity->multipliedBy(Decimal::from($settings['risk_per_trade_pct']))->dividedBy(Decimal::from('100'), 18, RoundingMode::DOWN);
        $riskQty = $budget->dividedBy($risk, 0, RoundingMode::DOWN);
        $positionQty = $equity->multipliedBy(Decimal::from($settings['max_position_pct']))->dividedBy(Decimal::from('100'), 18, RoundingMode::DOWN)->dividedBy($bound, 0, RoundingMode::DOWN);
        if ($notionalCap !== null) {
            $sleeveQty = $notionalCap->compareTo(Decimal::from('0')) <= 0 ? Decimal::from('0') : $notionalCap->dividedBy($bound, 0, RoundingMode::DOWN);
            $positionQty = $positionQty->compareTo($sleeveQty) > 0 ? $sleeveQty : $positionQty;
        }
        $costPerShare = $this->costs->buyReserve($settings['cost_schedule'], Decimal::from('1'));
        $cashQty = $cash->dividedBy($bound->plus($costPerShare), 0, RoundingMode::DOWN);
        $quantity = $riskQty;
        foreach ([$positionQty, $cashQty] as $cap) {
            $quantity = $quantity->compareTo($cap) > 0 ? $cap : $quantity;
        }

        return ['quantity' => (string) $quantity, 'risk_budget' => (string) $budget, 'risk_per_share' => (string) $risk,
            'risk_quantity' => (string) $riskQty, 'position_quantity' => (string) $positionQty, 'cash_quantity' => (string) $cashQty,
            'reason' => $quantity->compareTo(Decimal::from('0')) > 0 ? null : 'whole_share_size_zero'];
    }
}
