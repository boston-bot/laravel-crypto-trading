<?php

namespace App\Services\Execution;

use App\Enums\BrokerType;
use App\Enums\OrderStatus;
use App\Enums\TradingDecisionStatus;
use App\Models\BrokerCredential;
use App\Models\BrokerOrder;
use App\Models\TradeDecision;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Coinbase\CoinbaseClient;
use App\Services\Broker\Coinbase\CoinbaseMapper;
use App\Services\Broker\Robinhood\RobinhoodClient;
use App\Services\Broker\Robinhood\RobinhoodMapper;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;

class TradeExecutionService
{
    public function __construct(
        private readonly PaperBrokerAdapter $paperBrokerAdapter,
        private readonly BrokerCredentialResolver $credentialResolver,
        private readonly RobinhoodClient $robinhoodClient,
        private readonly RobinhoodMapper $robinhoodMapper,
        private readonly CoinbaseClient $coinbaseClient,
        private readonly CoinbaseMapper $coinbaseMapper,
    ) {}

    public function submitDecision(int $decisionId): void
    {
        $decision = TradeDecision::query()
            ->with(['asset', 'brokerAccount'])
            ->findOrFail($decisionId);

        if ($decision->status !== TradingDecisionStatus::APPROVED) {
            return;
        }

        if ($decision->side === null || $decision->brokerAccount === null || $decision->asset === null) {
            throw new ModelNotFoundException('Trade decision is missing account, asset, or side details.');
        }

        $mode = (string) config('broker.mode', 'paper');
        if ($mode === 'paper') {
            $this->paperBrokerAdapter->submit($decision->brokerAccount, $decision->asset, $decision);

            $decision->update([
                'status' => TradingDecisionStatus::FILLED->value,
            ]);

            return;
        }

        if ($decision->requires_human_approval && ($decision->approved_at === null || $decision->signal_expires_at?->isPast())) {
            throw new RuntimeException('Live order submission requires a current human approval for an unexpired proposal.');
        }

        $broker = $decision->brokerAccount->broker instanceof BrokerType
            ? $decision->brokerAccount->broker
            : BrokerType::tryFrom((string) $decision->brokerAccount->broker) ?? BrokerType::default();

        if ($broker !== BrokerType::COINBASE) {
            throw new RuntimeException('Live order execution is restricted to Coinbase; other brokers are paper-data compatibility only.');
        }

        $credential = $this->credentialResolver->resolve(
            $broker,
            $decision->brokerAccount->broker_credential_id !== null ? (int) $decision->brokerAccount->broker_credential_id : null,
        ) ?? $this->credentialResolver->resolve($broker);
        if ($credential === null) {
            throw new ModelNotFoundException('No active '.$broker->value.' broker credential found.');
        }

        [$payload, $raw, $mapped] = $this->submitLiveOrder($broker, $credential, $decision);

        BrokerOrder::query()->create([
            'broker_account_id' => $decision->broker_account_id,
            'asset_id' => $decision->asset_id,
            'trade_decision_id' => $decision->id,
            'external_order_id' => $mapped['external_order_id'] ?? null,
            'client_order_id' => $decision->idempotency_key,
            'side' => $decision->side->value,
            'order_type' => $mapped['order_type'] ?? 'market',
            'time_in_force' => $mapped['time_in_force'] ?? 'gtc',
            'requested_quantity' => $decision->requested_quantity,
            'requested_notional' => $decision->requested_notional,
            'requested_price' => $mapped['requested_price'] ?? null,
            'status' => $mapped['status'] ?? OrderStatus::SUBMITTED->value,
            'filled_quantity' => $mapped['filled_quantity'] ?? null,
            'filled_notional' => $mapped['filled_notional'] ?? null,
            'avg_fill_price' => $mapped['avg_fill_price'] ?? null,
            'submitted_at' => $mapped['submitted_at'] ?? now(),
            'filled_at' => $mapped['filled_at'] ?? null,
            'raw_request_json' => $payload,
            'raw_response_json' => $raw['body'] ?? [],
        ]);

        $decision->update([
            'status' => TradingDecisionStatus::SUBMITTED->value,
        ]);
    }

    public function reconcileOrder(BrokerOrder $order): void
    {
        if (str_starts_with((string) $order->external_order_id, 'paper_')) {
            return;
        }

        $order->loadMissing('brokerAccount');
        $broker = $order->brokerAccount?->broker instanceof BrokerType
            ? $order->brokerAccount->broker
            : BrokerType::tryFrom((string) $order->brokerAccount?->broker) ?? BrokerType::default();

        $credential = $this->credentialResolver->resolve(
            $broker,
            $order->brokerAccount?->broker_credential_id !== null ? (int) $order->brokerAccount->broker_credential_id : null,
        ) ?? $this->credentialResolver->resolve($broker);
        if ($credential === null || empty($order->external_order_id)) {
            return;
        }

        [$raw, $mapped] = $this->fetchOrder($broker, $credential, (string) $order->external_order_id);

        $order->update([
            'status' => $mapped['status'] ?? $order->status,
            'filled_quantity' => $mapped['filled_quantity'] ?? $order->filled_quantity,
            'filled_notional' => $mapped['filled_notional'] ?? $order->filled_notional,
            'avg_fill_price' => $mapped['avg_fill_price'] ?? $order->avg_fill_price,
            'filled_at' => $mapped['filled_at'] ?? $order->filled_at,
            'raw_response_json' => $raw['body'] ?? $order->raw_response_json,
        ]);

        if ($order->tradeDecision === null) {
            return;
        }

        $decisionStatus = match ($order->status) {
            OrderStatus::FILLED => TradingDecisionStatus::FILLED,
            OrderStatus::PARTIALLY_FILLED => TradingDecisionStatus::PARTIALLY_FILLED,
            OrderStatus::CANCELLED => TradingDecisionStatus::CANCELLED,
            OrderStatus::REJECTED => TradingDecisionStatus::REJECTED,
            default => TradingDecisionStatus::SUBMITTED,
        };

        $order->tradeDecision->update([
            'status' => $decisionStatus->value,
        ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function submitLiveOrder(BrokerType $broker, BrokerCredential $credential, TradeDecision $decision): array
    {
        return match ($broker) {
            BrokerType::COINBASE => $this->submitCoinbaseOrder($credential, $decision),
            BrokerType::ROBINHOOD => $this->submitRobinhoodOrder($credential, $decision),
        };
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function fetchOrder(BrokerType $broker, BrokerCredential $credential, string $externalOrderId): array
    {
        return match ($broker) {
            BrokerType::COINBASE => $this->fetchCoinbaseOrder($credential, $externalOrderId),
            BrokerType::ROBINHOOD => $this->fetchRobinhoodOrder($credential, $externalOrderId),
        };
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function submitRobinhoodOrder(BrokerCredential $credential, TradeDecision $decision): array
    {
        $payload = [
            'symbol' => $decision->asset->symbol,
            'side' => $decision->side->value,
            'type' => 'market',
            'time_in_force' => 'gtc',
            'client_order_id' => $decision->idempotency_key,
            'quote_amount' => (float) $decision->requested_notional,
            'quantity' => (float) $decision->requested_quantity,
        ];

        $raw = $this->robinhoodClient->placeOrder($credential, $payload);
        $assetLookup = $this->robinhoodMapper->buildAssetLookup();
        $mapped = $this->robinhoodMapper->mapOrder((array) ($raw['body'] ?? []), $assetLookup);

        return [$payload, $raw, $mapped];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function fetchRobinhoodOrder(BrokerCredential $credential, string $externalOrderId): array
    {
        $raw = $this->robinhoodClient->getOrder($credential, $externalOrderId);
        $assetLookup = $this->robinhoodMapper->buildAssetLookup();
        $mapped = $this->robinhoodMapper->mapOrder((array) ($raw['body'] ?? []), $assetLookup);

        return [$raw, $mapped];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function submitCoinbaseOrder(BrokerCredential $credential, TradeDecision $decision): array
    {
        $productId = strtoupper($decision->asset->symbol).'-'.strtoupper((string) config('broker.coinbase.quote_currency', 'USD'));
        $orderConfig = [
            'market_market_ioc' => array_filter([
                'quote_size' => $decision->requested_notional !== null ? (string) $decision->requested_notional : null,
                'base_size' => $decision->requested_quantity !== null ? (string) $decision->requested_quantity : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];

        if ($orderConfig['market_market_ioc'] === []) {
            throw new RuntimeException('Coinbase order requires requested_notional or requested_quantity.');
        }

        $payload = [
            'client_order_id' => $decision->idempotency_key,
            'product_id' => $productId,
            'side' => strtoupper($decision->side->value),
            'order_configuration' => $orderConfig,
        ];

        $raw = $this->coinbaseClient->placeOrder($credential, $payload);
        $body = (array) ($raw['body'] ?? []);
        if (($body['success'] ?? false) !== true && isset($body['error_response'])) {
            $error = (array) $body['error_response'];
            $message = (string) ($error['error'] ?? $error['message'] ?? 'Unknown Coinbase order submission error');
            throw new RuntimeException('Coinbase order rejected: '.$message);
        }

        $orderId = (string) data_get($body, 'success_response.order_id', '');
        $orderRaw = $orderId !== ''
            ? $this->coinbaseClient->getOrder($credential, $orderId)
            : $raw;
        $orderBody = (array) (($orderRaw['body']['order'] ?? $orderRaw['body']) ?: []);

        $assetLookup = $this->coinbaseMapper->buildAssetLookup();
        $mapped = $this->coinbaseMapper->mapOrder($orderBody, $assetLookup);

        return [$payload, $orderRaw, $mapped];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function fetchCoinbaseOrder(BrokerCredential $credential, string $externalOrderId): array
    {
        $raw = $this->coinbaseClient->getOrder($credential, $externalOrderId);
        $body = (array) (($raw['body']['order'] ?? $raw['body']) ?: []);
        $assetLookup = $this->coinbaseMapper->buildAssetLookup();
        $mapped = $this->coinbaseMapper->mapOrder($body, $assetLookup);

        return [$raw, $mapped];
    }
}
