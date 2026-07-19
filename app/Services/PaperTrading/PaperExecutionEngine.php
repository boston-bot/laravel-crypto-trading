<?php

namespace App\Services\PaperTrading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\TradingDecisionStatus;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\BrokerOrder;
use App\Models\MarketQuote;
use App\Models\PaperLedgerEntry;
use App\Models\PaperOrderEvent;
use App\Models\PaperPosition;
use App\Models\PaperSession;
use App\Models\TradeDecision;
use App\Services\Execution\SlippageModel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaperExecutionEngine
{
    public function __construct(
        private readonly SlippageModel $slippageModel,
        private readonly PaperPortfolioValuationService $valuationService,
        private readonly PaperAttributionService $attributionService,
        private readonly PaperReservationService $reservations,
    ) {}

    public function submit(BrokerAccount $account, Asset $asset, TradeDecision $decision): BrokerOrder
    {
        $earliest = data_get($decision->order_intent_json, 'earliest_execution_at');
        $quoteQuery = MarketQuote::query()->where('asset_id', $asset->id);
        $quote = $earliest !== null
            ? $quoteQuery->where('snapshot_time', '>', $earliest)->oldest('snapshot_time')->first()
            : $quoteQuery->latest('snapshot_time')->first();
        if ($earliest !== null && $quote === null) {
            throw new RuntimeException('Paper execution requires an executable observation strictly after the order-intent cutoff.');
        }
        $referencePrice = $earliest !== null
            ? (float) ($quote?->mid_price ?: $quote?->last_price)
            : $this->resolveReferencePrice($decision, $quote);
        if ($referencePrice <= 0) {
            throw new RuntimeException('The executable observation does not contain a valid price.');
        }
        $spreadBps = (float) ($quote?->spread_bps ?? config('trading.paper.default_spread_bps', 35.0));
        $liquidityScore = (float) ($quote?->liquidity_score ?? 0.6);
        $volatility = (float) ($decision->signal_context_json['ta']['atr_pct'] ?? 0.03);
        $requestedNotional = max(0.0, (float) ($decision->requested_notional ?? 0.0));
        $requestedQuantity = max(0.0, (float) ($decision->requested_quantity ?? 0.0));
        if ($requestedNotional <= 0 && $requestedQuantity > 0) {
            $requestedNotional = $requestedQuantity * $referencePrice;
        }
        if ($requestedNotional <= 0) {
            throw new RuntimeException('Paper order notional must be greater than zero.');
        }

        $side = $decision->side ?? OrderSide::BUY;
        $slippageBps = $this->slippageModel->estimateSlippageBps($requestedNotional, $volatility, $spreadBps, $liquidityScore);
        $fillPrice = $this->slippageModel->estimateFillPrice($referencePrice, $side, $requestedNotional, $volatility, $spreadBps, $liquidityScore);
        $filledQuantity = $requestedQuantity > 0 ? $requestedQuantity : round($requestedNotional / $referencePrice, 12);
        $filledNotional = round($filledQuantity * $fillPrice, 8);
        $feeBps = (float) data_get($decision->order_intent_json, 'expected_costs.fee_bps', config('trading.paper.taker_fee_bps', 60.0));
        $fee = round($filledNotional * ($feeBps / 10000), 8);
        $session = PaperSession::query()
            ->where('broker_account_id', $account->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
        if ($session === null) {
            throw new RuntimeException('No active paper session. Start a virtual or mirrored session before approving paper orders.');
        }
        $intentHash = $this->intentHash($session, $asset, $decision, $side, $requestedNotional, $requestedQuantity);
        $reservation = $this->reservations->reserve(
            $session,
            $decision,
            $asset,
            $side,
            $side === OrderSide::BUY ? $filledNotional + $fee : 0.0,
            $filledQuantity,
            $intentHash,
        );
        if ($reservation->status === 'filled' && $reservation->broker_order_id !== null) {
            return BrokerOrder::query()->findOrFail($reservation->broker_order_id);
        }

        $order = DB::transaction(function () use ($account, $asset, $decision, $quote, $side, $referencePrice, $spreadBps, $slippageBps, $fillPrice, $filledNotional, $filledQuantity, $fee, $feeBps, $reservation, $intentHash, $earliest): BrokerOrder {
            $lockedDecision = TradeDecision::query()->whereKey($decision->id)->lockForUpdate()->firstOrFail();
            $clientOrderId = 'paper_'.hash('sha256', (string) $lockedDecision->idempotency_key);
            $existing = BrokerOrder::query()->where('client_order_id', $clientOrderId)->first();
            if ($existing !== null) {
                return $existing;
            }

            $session = PaperSession::query()
                ->whereKey($reservation->paper_session_id)
                ->lockForUpdate()
                ->firstOrFail();

            $position = PaperPosition::query()
                ->where('paper_session_id', $session->id)
                ->where('asset_id', $asset->id)
                ->lockForUpdate()
                ->first();

            $fillId = 'paper-fill-'.hash('sha256', $clientOrderId.'|'.$filledQuantity.'|'.$fillPrice);
            $order = BrokerOrder::query()->create([
                'broker_account_id' => $account->id,
                'paper_session_id' => $session->id,
                'asset_id' => $asset->id,
                'trade_decision_id' => $lockedDecision->id,
                'external_order_id' => 'paper_'.hash('sha256', $clientOrderId),
                'client_order_id' => $clientOrderId,
                'side' => $side->value,
                'order_type' => 'market',
                'time_in_force' => 'ioc',
                'requested_quantity' => $lockedDecision->requested_quantity,
                'requested_notional' => $lockedDecision->requested_notional,
                'requested_price' => $referencePrice,
                'status' => OrderStatus::FILLED->value,
                'filled_quantity' => $filledQuantity,
                'filled_notional' => $filledNotional,
                'avg_fill_price' => $fillPrice,
                'fee_amount' => $fee,
                'submitted_at' => now(),
                'filled_at' => now(),
                'raw_request_json' => ['mode' => 'paper', 'session_id' => $session->id, 'decision_id' => $lockedDecision->id, 'reference_price' => $referencePrice, 'spread_bps' => $spreadBps, 'intent_hash' => $intentHash, 'reservation_id' => $reservation->id],
                'raw_response_json' => ['mode' => 'paper', 'fill_id' => $fillId, 'fill_price' => $fillPrice, 'slippage_bps' => $slippageBps, 'fee_bps' => $feeBps, 'fee' => $fee],
            ]);

            [$position, $realizedPnl] = $this->applyFill($session, $account, $asset, $side, $filledQuantity, $fillPrice, $filledNotional, $fee, $position);
            $this->postLedger($session, $asset, $order, $side, $fillId, $filledQuantity, $fillPrice, $filledNotional, $fee);
            $this->reservations->consume($reservation->id, $order, $fillId);

            PaperOrderEvent::query()->create([
                'broker_order_id' => $order->id,
                'trade_decision_id' => $lockedDecision->id,
                'broker_account_id' => $account->id,
                'paper_session_id' => $session->id,
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
                'fill_id' => $fillId,
                'fee' => $fee,
                'payload_json' => ['mode' => 'paper', 'observation_id' => $quote?->id, 'observation_time' => $quote?->snapshot_time?->toIso8601String(), 'intent_earliest_execution_at' => $earliest],
            ]);
            $this->attributionService->record($lockedDecision, $order, $position, $realizedPnl);
            $lockedDecision->update(['status' => TradingDecisionStatus::FILLED->value]);

            return $order;
        });

        $this->valuationService->snapshot($account);

        return $order->fresh();
    }

    private function intentHash(PaperSession $session, Asset $asset, TradeDecision $decision, OrderSide $side, float $notional, float $quantity): string
    {
        if ($decision->order_intent_hash !== null) {
            return (string) $decision->order_intent_hash;
        }
        $payload = [
            'schema_version' => '1.0',
            'paper_session_id' => $session->id,
            'strategy_version_id' => $session->strategy_version_id,
            'universe_version_id' => $session->universe_version_id,
            'asset_id' => $asset->id,
            'side' => $side->value,
            'requested_notional' => round($notional, 8),
            'requested_quantity' => round($quantity, 12),
            'idempotency_key' => $decision->idempotency_key,
        ];
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function resolveReferencePrice(TradeDecision $decision, ?MarketQuote $quote): float
    {
        $price = (float) ($decision->market_context_json['reference_price'] ?? $quote?->mid_price ?? $quote?->last_price ?? 0.0);
        if ($price <= 0) {
            throw new RuntimeException('Paper execution requires an observed decision or quote price; synthetic fallback prices are prohibited.');
        }

        return $price;
    }

    /** @return array{0: PaperPosition, 1: float|null} */
    private function applyFill(PaperSession $session, BrokerAccount $account, Asset $asset, OrderSide $side, float $quantity, float $price, float $notional, float $fee, ?PaperPosition $position): array
    {
        $position ??= PaperPosition::query()->create([
            'broker_account_id' => $account->id,
            'paper_session_id' => $session->id,
            'asset_id' => $asset->id,
            'quantity' => 0,
            'cost_basis' => 0,
            'realized_pnl' => 0,
            'updated_snapshot_at' => now(),
        ]);
        $currentQty = (float) $position->quantity;
        $currentCost = (float) $position->cost_basis;

        if ($side === OrderSide::BUY) {
            $newQty = $currentQty + $quantity;
            $newCost = $currentCost + $notional + $fee;
            $position->update([
                'quantity' => $newQty,
                'avg_entry_price' => $newCost / $newQty,
                'cost_basis' => $newCost,
                'market_price' => $price,
                'market_value' => $newQty * $price,
                'unrealized_pnl' => ($newQty * $price) - $newCost,
                'opened_at' => $position->opened_at ?? now(),
                'closed_at' => null,
                'updated_snapshot_at' => now(),
            ]);

            return [$position->fresh(), null];
        }

        $averageCost = $currentQty > 0 ? $currentCost / $currentQty : 0.0;
        $relievedCost = $averageCost * $quantity;
        $realizedPnl = $notional - $fee - $relievedCost;
        $remainingQty = max(0.0, $currentQty - $quantity);
        $remainingCost = max(0.0, $currentCost - $relievedCost);
        $position->update([
            'quantity' => $remainingQty,
            'avg_entry_price' => $remainingQty > 0 ? $remainingCost / $remainingQty : null,
            'cost_basis' => $remainingCost,
            'market_price' => $price,
            'market_value' => $remainingQty * $price,
            'unrealized_pnl' => ($remainingQty * $price) - $remainingCost,
            'realized_pnl' => (float) $position->realized_pnl + $realizedPnl,
            'closed_at' => $remainingQty <= 0.000000000001 ? now() : null,
            'updated_snapshot_at' => now(),
        ]);

        return [$position->fresh(), round($realizedPnl, 8)];
    }

    private function postLedger(PaperSession $session, Asset $asset, BrokerOrder $order, OrderSide $side, string $fillId, float $quantity, float $price, float $notional, float $fee): void
    {
        $principalType = $side === OrderSide::BUY ? 'buy_principal' : 'sell_proceeds';
        PaperLedgerEntry::query()->create([
            'paper_session_id' => $session->id,
            'asset_id' => $asset->id,
            'broker_order_id' => $order->id,
            'entry_type' => $principalType,
            'fill_id' => $fillId,
            'cash_delta' => $side === OrderSide::BUY ? -$notional : $notional,
            'quantity_delta' => $side === OrderSide::BUY ? $quantity : -$quantity,
            'unit_price' => $price,
            'occurred_at' => now(),
        ]);
        PaperLedgerEntry::query()->create([
            'paper_session_id' => $session->id,
            'asset_id' => $asset->id,
            'broker_order_id' => $order->id,
            'entry_type' => $side === OrderSide::BUY ? 'buy_fee' : 'sell_fee',
            'fill_id' => $fillId,
            'cash_delta' => -$fee,
            'fee' => $fee,
            'occurred_at' => now(),
        ]);
    }
}
