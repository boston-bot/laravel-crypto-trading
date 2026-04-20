<?php

namespace App\Services\PaperTrading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\BrokerOrder;
use App\Models\MarketQuote;
use App\Models\PaperOrderEvent;
use App\Models\PaperPosition;
use App\Models\TradeDecision;
use App\Services\Execution\SlippageModel;
use Illuminate\Support\Str;

class PaperExecutionEngine
{
    public function __construct(
        private readonly SlippageModel $slippageModel,
        private readonly PaperPortfolioValuationService $valuationService,
        private readonly PaperAttributionService $attributionService,
    ) {
    }

    public function submit(BrokerAccount $account, Asset $asset, TradeDecision $decision): BrokerOrder
    {
        $quote = MarketQuote::query()
            ->where('asset_id', $asset->id)
            ->latest('snapshot_time')
            ->first();

        $referencePrice = $this->resolveReferencePrice($decision, $quote);
        $spreadBps = (float) ($quote?->spread_bps ?? config('trading.paper.default_spread_bps', 35.0));
        $liquidityScore = (float) ($quote?->liquidity_score ?? 0.6);
        $volatility = (float) ($decision->signal_context_json['ta']['atr_pct'] ?? 0.03);

        $requestedNotional = max(0.0, (float) ($decision->requested_notional ?? 0.0));
        $requestedQuantity = max(0.0, (float) ($decision->requested_quantity ?? 0.0));

        if ($requestedNotional <= 0 && $requestedQuantity > 0 && $referencePrice > 0) {
            $requestedNotional = $requestedQuantity * $referencePrice;
        }

        $side = $decision->side ?? OrderSide::BUY;
        $slippageBps = $this->slippageModel->estimateSlippageBps(
            $requestedNotional,
            $volatility,
            $spreadBps,
            $liquidityScore,
        );

        $fillPrice = $this->slippageModel->estimateFillPrice(
            $referencePrice,
            $side,
            $requestedNotional,
            $volatility,
            $spreadBps,
            $liquidityScore,
        );

        $filledNotional = $requestedNotional;
        $filledQuantity = $fillPrice > 0
            ? round($filledNotional / $fillPrice, 12)
            : $requestedQuantity;

        $order = BrokerOrder::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'trade_decision_id' => $decision->id,
            'external_order_id' => 'paper_'.Str::uuid(),
            'client_order_id' => 'paper_'.Str::uuid(),
            'side' => $side->value,
            'order_type' => 'market',
            'time_in_force' => 'gtc',
            'requested_quantity' => $decision->requested_quantity,
            'requested_notional' => $decision->requested_notional,
            'requested_price' => $referencePrice,
            'status' => OrderStatus::FILLED->value,
            'filled_quantity' => $filledQuantity,
            'filled_notional' => $filledNotional,
            'avg_fill_price' => $fillPrice,
            'submitted_at' => now(),
            'filled_at' => now(),
            'raw_request_json' => [
                'mode' => 'paper',
                'decision_id' => $decision->id,
                'reference_price' => $referencePrice,
                'spread_bps' => $spreadBps,
            ],
            'raw_response_json' => [
                'mode' => 'paper',
                'fill_price' => $fillPrice,
                'slippage_bps' => $slippageBps,
            ],
        ]);

        [$position, $realizedPnl] = $this->applyFill($account, $asset, $side, $filledQuantity, $fillPrice);

        PaperOrderEvent::query()->create([
            'broker_order_id' => $order->id,
            'trade_decision_id' => $decision->id,
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'event_type' => 'filled',
            'status' => OrderStatus::FILLED->value,
            'side' => $side->value,
            'event_time' => now(),
            'quantity' => $filledQuantity,
            'notional' => $filledNotional,
            'reference_price' => $referencePrice,
            'fill_price' => $fillPrice,
            'slippage_bps' => $slippageBps,
            'payload_json' => [
                'mode' => 'paper',
                'quote_snapshot_time' => $quote?->snapshot_time?->toIso8601String(),
            ],
        ]);

        $this->attributionService->record($decision, $order, $position, $realizedPnl);
        $this->valuationService->snapshot($account);

        return $order;
    }

    private function resolveReferencePrice(TradeDecision $decision, ?MarketQuote $quote): float
    {
        $fromDecision = (float) ($decision->market_context_json['reference_price'] ?? 0.0);
        if ($fromDecision > 0.0) {
            return $fromDecision;
        }

        $quoteMid = (float) ($quote?->mid_price ?? 0.0);
        if ($quoteMid > 0.0) {
            return $quoteMid;
        }

        $quoteLast = (float) ($quote?->last_price ?? 0.0);
        if ($quoteLast > 0.0) {
            return $quoteLast;
        }

        $relativeStrength = (float) ($decision->market_context_json['market_rank']['factor_breakdown']['relative_strength'] ?? 0.5);

        return round(100 + ($relativeStrength * 20), 8);
    }

    /**
     * @return array{0: PaperPosition, 1: float|null}
     */
    private function applyFill(
        BrokerAccount $account,
        Asset $asset,
        OrderSide $side,
        float $filledQuantity,
        float $fillPrice,
    ): array {
        $position = PaperPosition::query()->firstOrCreate(
            [
                'broker_account_id' => $account->id,
                'asset_id' => $asset->id,
            ],
            [
                'quantity' => 0,
                'cost_basis' => 0,
                'realized_pnl' => 0,
                'updated_snapshot_at' => now(),
            ]
        );

        $currentQty = (float) $position->quantity;
        $currentCostBasis = (float) $position->cost_basis;
        $avgEntry = $currentQty > 0 ? ($currentCostBasis / $currentQty) : 0.0;

        $realizedPnl = null;
        if ($side === OrderSide::BUY) {
            $newQty = $currentQty + $filledQuantity;
            $newCostBasis = $currentCostBasis + ($filledQuantity * $fillPrice);
            $newAvg = $newQty > 0 ? $newCostBasis / $newQty : 0.0;

            $position->update([
                'quantity' => $newQty,
                'avg_entry_price' => $newAvg,
                'cost_basis' => $newCostBasis,
                'market_price' => $fillPrice,
                'market_value' => $newQty * $fillPrice,
                'unrealized_pnl' => ($newQty * $fillPrice) - $newCostBasis,
                'opened_at' => $position->opened_at ?? now(),
                'closed_at' => null,
                'updated_snapshot_at' => now(),
            ]);

            return [$position->fresh(), null];
        }

        $sellQty = min($currentQty, $filledQuantity);
        if ($sellQty <= 0) {
            $position->update([
                'updated_snapshot_at' => now(),
            ]);

            return [$position->fresh(), null];
        }

        $realizedPnl = ($fillPrice - $avgEntry) * $sellQty;
        $remainingQty = max(0.0, $currentQty - $sellQty);
        $remainingCostBasis = $remainingQty > 0 ? $remainingQty * $avgEntry : 0.0;

        $position->update([
            'quantity' => $remainingQty,
            'avg_entry_price' => $remainingQty > 0 ? $avgEntry : null,
            'cost_basis' => $remainingCostBasis,
            'market_price' => $fillPrice,
            'market_value' => $remainingQty * $fillPrice,
            'unrealized_pnl' => ($remainingQty * $fillPrice) - $remainingCostBasis,
            'realized_pnl' => (float) $position->realized_pnl + $realizedPnl,
            'closed_at' => $remainingQty <= 0 ? now() : null,
            'updated_snapshot_at' => now(),
        ]);

        return [$position->fresh(), $realizedPnl];
    }
}
