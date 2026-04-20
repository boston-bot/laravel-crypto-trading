<?php

namespace App\Jobs;

use App\Data\Trading\TradeCandidate;
use App\Enums\OrderSide;
use App\Enums\TradeDecisionAction;
use App\Enums\TradingDecisionStatus;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\PolicyCheck;
use App\Models\StrategyRun;
use App\Models\TradeDecision;
use App\Services\Audit\DecisionJournalService;
use App\Services\Execution\OrderSizingService;
use App\Services\Risk\PolicyEngine;
use App\Services\Risk\RiskEngine;
use App\Services\Strategy\SignalAggregator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class CreateTradeDecisionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $strategyRunId,
        public readonly int $assetId,
        public readonly int $brokerAccountId,
    ) {
    }

    public function handle(
        SignalAggregator $signalAggregator,
        OrderSizingService $orderSizingService,
        RiskEngine $riskEngine,
        PolicyEngine $policyEngine,
        DecisionJournalService $decisionJournal,
    ): int {
        $strategyRun = StrategyRun::query()->findOrFail($this->strategyRunId);
        $asset = Asset::query()->findOrFail($this->assetId);
        $brokerAccount = BrokerAccount::query()->findOrFail($this->brokerAccountId);

        $signal = $signalAggregator->evaluate($asset);
        $decisionAction = $signal['decision'] ?? TradeDecisionAction::HOLD;
        $side = $signal['side'] ?? null;

        $referencePrice = $this->extractReferencePrice($signal);
        $size = $orderSizingService->size($brokerAccount, $referencePrice, $signal);
        $marketContext = (array) ($signal['market_context'] ?? []);
        $marketContext['sizing'] = [
            'stop_distance_pct' => $size['stop_distance_pct'] ?? null,
            'risk_budget_usd' => $size['risk_budget_usd'] ?? null,
            'regime_multiplier' => $size['regime_multiplier'] ?? null,
        ];

        $candidate = new TradeCandidate(
            assetId: $asset->id,
            symbol: $asset->symbol,
            side: $side ?? OrderSide::BUY,
            quantity: $size['quantity'],
            notionalUsd: $size['notional'],
            score: (float) ($signal['score'] ?? 0.0),
            confidence: (float) ($signal['confidence'] ?? 0.0),
            marketContext: $marketContext,
            signalContext: (array) ($signal['signal_context'] ?? []),
        );

        $riskResult = $riskEngine->evaluate($candidate, $brokerAccount);
        $policyResult = $policyEngine->evaluate($candidate, $asset);
        $status = $this->resolveStatus(
            $decisionAction,
            $riskResult->passed,
            $policyResult->passed,
            $policyResult->requiresHumanApproval,
        );

        $tradeDecision = TradeDecision::query()->create([
            'strategy_run_id' => $strategyRun->id,
            'broker_account_id' => $brokerAccount->id,
            'asset_id' => $asset->id,
            'decision' => $decisionAction->value,
            'side' => $side?->value,
            'score' => $candidate->score,
            'confidence' => $candidate->confidence,
            'requested_quantity' => $candidate->quantity,
            'requested_notional' => $candidate->notionalUsd,
            'market_context_json' => $candidate->marketContext,
            'signal_context_json' => $candidate->signalContext,
            'risk_context_json' => [
                'passed' => $riskResult->passed,
                'violations' => $riskResult->violations,
                'context' => $riskResult->context,
            ],
            'policy_result_json' => [
                'passed' => $policyResult->passed,
                'requires_human_approval' => $policyResult->requiresHumanApproval,
                'checks' => $policyResult->checks,
                'messages' => $policyResult->messages,
                'context' => $policyResult->context,
            ],
            'requires_human_approval' => $policyResult->requiresHumanApproval,
            'status' => $status->value,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        foreach ($policyResult->checks as $policyName => $result) {
            PolicyCheck::query()->create([
                'trade_decision_id' => $tradeDecision->id,
                'policy_name' => $policyName,
                'result' => $result,
                'message' => $policyResult->messages[$policyName] ?? null,
                'context_json' => $policyResult->context,
                'checked_at' => now(),
            ]);
        }

        $decisionJournal->record($tradeDecision, [
            'risk' => $riskResult,
            'policy' => $policyResult,
        ]);

        return $tradeDecision->id;
    }

    private function resolveStatus(
        TradeDecisionAction $action,
        bool $riskPassed,
        bool $policyPassed,
        bool $requiresHumanApproval,
    ): TradingDecisionStatus {
        if ($action === TradeDecisionAction::HOLD) {
            return TradingDecisionStatus::BLOCKED_BY_POLICY;
        }

        if (! $riskPassed || ! $policyPassed) {
            return TradingDecisionStatus::BLOCKED_BY_POLICY;
        }

        if ($requiresHumanApproval) {
            return TradingDecisionStatus::AWAITING_HUMAN_APPROVAL;
        }

        return TradingDecisionStatus::APPROVED;
    }

    /**
     * @param array<string, mixed> $signal
     */
    private function extractReferencePrice(array $signal): float
    {
        $contextPrice = (float) ($signal['market_context']['reference_price'] ?? 0.0);
        if ($contextPrice > 0.0) {
            return round($contextPrice, 8);
        }

        $quoteMid = (float) ($signal['market_context']['quote']['mid_price'] ?? 0.0);
        if ($quoteMid > 0.0) {
            return round($quoteMid, 8);
        }

        $relativeStrength = (float) ($signal['market_context']['market_rank']['factor_breakdown']['relative_strength'] ?? 0.5);

        return round(100 + ($relativeStrength * 20), 8);
    }
}
