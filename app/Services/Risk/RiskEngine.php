<?php

namespace App\Services\Risk;

use App\Contracts\PortfolioContext;
use App\Data\Trading\RiskEvaluation;
use App\Data\Trading\TradeCandidate;
use App\Models\Asset;
use App\Models\TradeAttribution;
use App\Services\Portfolio\CorrelationService;

class RiskEngine
{
    public function __construct(
        private readonly ExposureService $exposureService,
        private readonly DrawdownService $drawdownService,
        private readonly CorrelationService $correlationService,
    ) {}

    public function evaluate(TradeCandidate $candidate, PortfolioContext $portfolioContext): RiskEvaluation
    {
        $violations = [];
        $context = [
            'candidate_notional' => $candidate->notionalUsd,
            'portfolio_context_id' => $portfolioContext->contextId(),
            'portfolio_context_hash' => $portfolioContext->contentHash(),
            'mode' => $portfolioContext->mode(),
            'equity' => $portfolioContext->equity(),
            'available_cash' => $portfolioContext->availableCash(),
            'open_positions' => $this->exposureService->openPositionCount($portfolioContext),
        ];
        $equity = max(1.0, $portfolioContext->equity());
        $openExposure = $this->exposureService->openExposureNotional($portfolioContext);
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

        if ($candidate->side->value === 'buy' && $portfolioContext->positionQuantity($candidate->assetId) > 0) {
            $violations[] = 'Pyramiding is disabled for existing long positions.';
        }

        if ($candidate->side->value === 'buy' && $portfolioContext->availableCash() < $candidate->notionalUsd) {
            $violations[] = 'Insufficient buying power.';
        }

        if ($candidate->side->value === 'sell') {
            $positionQuantity = $portfolioContext->positionQuantity($candidate->assetId);
            if ($positionQuantity <= 0) {
                $violations[] = 'A long position is required before submitting a sell exit.';
            } elseif ($candidate->quantity > $positionQuantity) {
                $violations[] = 'Sell quantity exceeds the current long position.';
            }
        }

        if (
            $this->exposureService->openPositionCount($portfolioContext) >= (int) config('risk.max_open_positions')
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
            $correlatedExposurePct = $this->correlationService->correlatedExposurePct($portfolioContext, $candidateAsset)
                + $candidateExposurePct;
            $context['correlated_exposure_pct'] = $correlatedExposurePct;

            if ($correlatedExposurePct > (float) config('risk.max_correlated_exposure_pct', 70.0)) {
                $violations[] = 'Correlated exposure cap would be exceeded.';
            }
        }

        if ($portfolioContext->valuationTime()->lt(now()->subMinutes((int) config('risk.stale_account_minutes', 30)))) {
            $violations[] = 'Portfolio context is stale.';
        }

        $dailyLossPct = $this->drawdownService->dailyLossPct($portfolioContext);
        $weeklyLossPct = $this->drawdownService->weeklyLossPct($portfolioContext);
        $drawdownPct = $this->drawdownService->drawdownPct($portfolioContext);

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

        $consecutiveLosses = $this->consecutiveLossCount($portfolioContext);
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

    private function consecutiveLossCount(PortfolioContext $context): int
    {
        $lookback = max(1, (int) config('risk.consecutive_loss_lookback', 8));
        $query = TradeAttribution::query()
            ->whereNotNull('realized_pnl')
            ->when(
                $context->mode() === 'paper',
                fn ($query) => $query->where('paper_session_id', $context->paperSessionId()),
                fn ($query) => $query->whereHas('tradeDecision', fn ($decision) => $decision->where('broker_account_id', $context->brokerAccountId())),
            );
        $recent = $query->latest('attributed_at')
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
