<?php

namespace App\Services\Risk;

use App\Data\Trading\RiskEvaluation;
use App\Data\Trading\TradeCandidate;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\TradeAttribution;
use App\Services\Portfolio\CorrelationService;

class RiskEngine
{
    public function __construct(
        private readonly ExposureService $exposureService,
        private readonly DrawdownService $drawdownService,
        private readonly CorrelationService $correlationService,
    ) {}

    public function evaluate(TradeCandidate $candidate, BrokerAccount $account): RiskEvaluation
    {
        $violations = [];
        $context = [
            'candidate_notional' => $candidate->notionalUsd,
            'equity' => (float) $account->equity,
            'buying_power' => (float) $account->buying_power,
            'open_positions' => $this->exposureService->openPositionCount($account),
        ];
        $equity = max(1.0, (float) $account->equity);
        $openExposure = $this->exposureService->openExposureNotional($account);
        $candidateExposurePct = ($candidate->notionalUsd / $equity) * 100;
        $portfolioHeatPct = (($candidate->side->value === 'buy'
            ? $openExposure + $candidate->notionalUsd
            : max(0, $openExposure - $candidate->notionalUsd)) / $equity) * 100;
        $context['open_exposure_notional'] = $openExposure;
        $context['candidate_exposure_pct'] = $candidateExposurePct;
        $context['portfolio_heat_pct'] = $portfolioHeatPct;

        $regimeState = (string) ($candidate->marketContext['regime']['state'] ?? 'neutral');
        $atrPct = (float) ($candidate->signalContext['ta']['atr_pct'] ?? 0.0);
        $executionPenalty = (float) ($candidate->signalContext['ta']['features']['execution_quality_penalty'] ?? 0.0);
        $spreadBps = (float) ($candidate->marketContext['quote']['spread_bps'] ?? 0.0);
        $slippageEstimateBps = (float) ($candidate->marketContext['quote']['slippage_bps_estimate'] ?? 0.0);
        $context['regime_state'] = $regimeState;
        $context['atr_pct'] = $atrPct;
        $context['execution_penalty'] = $executionPenalty;
        $context['spread_bps'] = $spreadBps;
        $context['slippage_bps_estimate'] = $slippageEstimateBps;

        if ($candidate->notionalUsd > (float) config('risk.max_position_notional_usd')) {
            $violations[] = 'Candidate notional exceeds max_position_notional_usd.';
        }

        if ($candidate->side->value === 'buy' && $candidateExposurePct > (float) config('risk.max_asset_exposure_pct', 20.0)) {
            $violations[] = 'Candidate exceeds the per-asset equity exposure cap.';
        }

        if ($candidate->side->value === 'buy' && $account->positions()->where('asset_id', $candidate->assetId)->where('quantity', '>', 0)->exists()) {
            $violations[] = 'Pyramiding is disabled for existing long positions.';
        }

        if ($candidate->side->value === 'buy' && (float) $account->buying_power < $candidate->notionalUsd) {
            $violations[] = 'Insufficient buying power.';
        }

        if ($candidate->side->value === 'sell') {
            $positionQuantity = (float) $account->positions()->where('asset_id', $candidate->assetId)->value('quantity');
            if ($positionQuantity <= 0) {
                $violations[] = 'A long position is required before submitting a sell exit.';
            } elseif ($candidate->quantity > $positionQuantity) {
                $violations[] = 'Sell quantity exceeds the current long position.';
            }
        }

        if (
            $this->exposureService->openPositionCount($account) >= (int) config('risk.max_open_positions')
            && $candidate->side->value === 'buy'
        ) {
            $violations[] = 'Max open positions reached.';
        }

        if (
            $candidate->side->value === 'buy'
            && $regimeState === 'risk_off'
        ) {
            $violations[] = 'Regime is risk_off; new long exposure blocked.';
        }

        if ($portfolioHeatPct > (float) config('risk.max_portfolio_heat_pct', 55.0)) {
            $violations[] = 'Portfolio heat cap would be exceeded.';
        }

        if ($atrPct > 0 && $atrPct > (float) config('risk.max_asset_atr_pct', 0.10)) {
            $violations[] = 'Asset volatility exceeds ATR cap.';
        }

        if ($executionPenalty > (float) config('risk.max_execution_penalty', 0.70)) {
            $violations[] = 'Execution quality penalty exceeds limit.';
        }

        if ($spreadBps > (float) config('risk.max_spread_bps', 180.0)) {
            $violations[] = 'Spread exceeds configured execution threshold.';
        }

        if ($slippageEstimateBps > (float) config('risk.max_slippage_bps', 180.0)) {
            $violations[] = 'Estimated slippage exceeds configured threshold.';
        }

        $candidateAsset = Asset::query()->find($candidate->assetId);
        if ($candidateAsset !== null && $candidate->side->value === 'buy') {
            $correlatedExposurePct = $this->correlationService->correlatedExposurePct($account, $candidateAsset)
                + $candidateExposurePct;
            $context['correlated_exposure_pct'] = $correlatedExposurePct;

            if ($correlatedExposurePct > (float) config('risk.max_correlated_exposure_pct', 70.0)) {
                $violations[] = 'Correlated exposure cap would be exceeded.';
            }
        }

        $snapshotAt = $account->snapshot_at;
        if ($snapshotAt === null || $snapshotAt->lt(now()->subMinutes((int) config('risk.stale_account_minutes', 30)))) {
            $violations[] = 'Account data is stale.';
        }

        $dailyLossPct = $this->drawdownService->dailyLossPct($account);
        $weeklyLossPct = $this->drawdownService->weeklyLossPct($account);
        $drawdownPct = $this->drawdownService->drawdownPct($account);

        $context['daily_loss_pct'] = $dailyLossPct;
        $context['weekly_loss_pct'] = $weeklyLossPct;
        $context['drawdown_pct'] = $drawdownPct;

        if ($dailyLossPct > (float) config('risk.max_daily_loss_pct')) {
            $violations[] = 'Daily loss threshold breached.';
        }

        if ($weeklyLossPct > (float) config('risk.max_weekly_loss_pct')) {
            $violations[] = 'Weekly loss threshold breached.';
        }

        if ($drawdownPct > (float) config('risk.max_drawdown_pct')) {
            $violations[] = 'Drawdown threshold breached.';
        }

        $consecutiveLosses = $this->consecutiveLossCount();
        $context['consecutive_losses'] = $consecutiveLosses;

        if ($consecutiveLosses >= (int) config('risk.max_consecutive_losses', 3)) {
            $violations[] = 'Consecutive loss brake active.';
        }

        return new RiskEvaluation(
            passed: $violations === [],
            violations: $violations,
            context: $context,
        );
    }

    private function consecutiveLossCount(): int
    {
        $lookback = max(1, (int) config('risk.consecutive_loss_lookback', 8));
        $recent = TradeAttribution::query()
            ->whereNotNull('realized_pnl')
            ->latest('attributed_at')
            ->limit($lookback)
            ->pluck('realized_pnl')
            ->map(fn ($value): float => (float) $value)
            ->values();

        $count = 0;
        foreach ($recent as $pnl) {
            if ($pnl >= 0) {
                break;
            }

            $count++;
        }

        return $count;
    }
}
