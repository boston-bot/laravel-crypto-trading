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

        // The legacy live branch is retired (CRYPTO-AUTOMATION-PLAN.md C0); live crypto orders belong to the future execution boundary.
        if ((string) config('broker.mode', 'paper') !== 'paper') {
            throw new RuntimeException('Legacy live order submission is retired.');
        }

        $this->paperBrokerAdapter->submit($decision->brokerAccount, $decision->asset, $decision);

        $decision->update([
            'status' => TradingDecisionStatus::FILLED->value,
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
