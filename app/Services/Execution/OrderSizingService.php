<?php

namespace App\Services\Execution;

use App\Models\BrokerAccount;
use App\Services\Risk\DrawdownService;
use App\Services\Risk\ExposureService;
use Illuminate\Support\Arr;

class OrderSizingService
{
    public function __construct(
        private readonly DrawdownService $drawdownService,
        private readonly ExposureService $exposureService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $signal
     * @return array{notional: float, quantity: float, stop_distance_pct: float, risk_budget_usd: float, regime_multiplier: float}
     */
    public function size(BrokerAccount $account, float $lastPrice, array $signal = []): array
    {
        $equity = max(0.0, (float) $account->equity);
        $buyingPower = max(0.0, (float) $account->buying_power);
        $regimeState = (string) Arr::get($signal, 'market_context.regime.state', 'neutral');
        $probability = (float) Arr::get($signal, 'signal_context.scoring.probability', Arr::get($signal, 'confidence', 0.5));
        $atrPct = (float) Arr::get($signal, 'signal_context.ta.atr_pct', config('trading.sizing.default_atr_pct', 0.03));
        $atrPct = $atrPct > 0 ? $atrPct : (float) config('trading.sizing.default_atr_pct', 0.03);

        $drawdownPct = $this->drawdownService->drawdownPct($account);
        $openExposure = $this->exposureService->openExposureNotional($account);
        $heatPct = $equity > 0 ? ($openExposure / $equity) * 100 : 0.0;

        $regimeMultiplier = match ($regimeState) {
            'risk_on' => 1.0,
            'neutral' => (float) config('trading.sizing.neutral_regime_multiplier', 0.6),
            default => 0.0,
        };

        $probabilityMultiplier = $this->clamp(0.55 + (($probability - 0.5) * 1.4), 0.35, 1.25);
        $drawdownMultiplier = $this->clamp(
            1 - ($drawdownPct / max(1.0, (float) config('trading.sizing.drawdown_scale_pct', 12.0))),
            0.3,
            1.0
        );
        $heatMultiplier = $this->clamp(
            1 - ($heatPct / max(1.0, (float) config('risk.max_portfolio_heat_pct', 55.0))),
            0.35,
            1.0
        );

        $baseRiskPct = (float) config('trading.sizing.base_risk_pct', 0.0075);
        $riskBudgetUsd = $equity * $baseRiskPct * $regimeMultiplier * $probabilityMultiplier * $drawdownMultiplier * $heatMultiplier;

        $stopDistancePct = max(
            $atrPct * (float) config('trading.sizing.atr_stop_multiple', 1.8),
            (float) config('trading.sizing.min_stop_distance_pct', 0.015)
        );
        $notionalFromRisk = $stopDistancePct > 0 ? ($riskBudgetUsd / $stopDistancePct) : 0.0;

        $maxPositionNotional = (float) config('trading.max_position_notional_usd', 15);
        $maxPositionPct = (float) config('trading.sizing.max_position_pct_of_equity', 0.25);
        $maxFromEquity = $equity * $maxPositionPct;

        $notional = min($notionalFromRisk, $maxPositionNotional, $buyingPower, $maxFromEquity);
        $notional = round(max(0.0, $notional), 8);

        $quantity = $lastPrice > 0
            ? round($notional / $lastPrice, 12)
            : 0.0;

        return [
            'notional' => $notional,
            'quantity' => $quantity,
            'stop_distance_pct' => round($stopDistancePct, 6),
            'risk_budget_usd' => round($riskBudgetUsd, 8),
            'regime_multiplier' => round($regimeMultiplier, 4),
        ];
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }
}
