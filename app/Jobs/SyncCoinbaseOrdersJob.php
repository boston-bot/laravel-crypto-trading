<?php

namespace App\Jobs;

use App\Enums\BrokerType;
use App\Models\BrokerAccount;
use App\Models\BrokerOrder;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Coinbase\CoinbaseClient;
use App\Services\Broker\Coinbase\CoinbaseMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class SyncCoinbaseOrdersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $credentialId = null,
        public readonly ?int $brokerAccountId = null,
    ) {}

    public function handle(
        CoinbaseClient $client,
        CoinbaseMapper $mapper,
        BrokerCredentialResolver $credentialResolver,
    ): void {
        $credential = $credentialResolver->resolve(BrokerType::COINBASE, $this->credentialId);
        $account = $this->resolveAccount();
        if ($credential === null || $account === null) {
            return;
        }

        $assetLookup = $mapper->buildAssetLookup();
        $orders = $client->getOrders($credential);

        foreach ($orders as $rawOrder) {
            $mapped = $mapper->mapOrder($rawOrder, $assetLookup);
            if (($mapped['asset_id'] ?? null) === null) {
                continue;
            }

            $externalOrderId = (string) ($mapped['external_order_id'] ?? Str::uuid());

            BrokerOrder::query()->updateOrCreate(
                [
                    'external_order_id' => $externalOrderId,
                ],
                [
                    'broker_account_id' => $account->id,
                    'asset_id' => $mapped['asset_id'],
                    'client_order_id' => $mapped['client_order_id'] ?? null,
                    'side' => $mapped['side'],
                    'order_type' => $mapped['order_type'] ?? 'market',
                    'time_in_force' => $mapped['time_in_force'],
                    'requested_quantity' => $mapped['requested_quantity'],
                    'requested_notional' => $mapped['requested_notional'],
                    'requested_price' => $mapped['requested_price'],
                    'status' => $mapped['status'],
                    'filled_quantity' => $mapped['filled_quantity'],
                    'filled_notional' => $mapped['filled_notional'],
                    'avg_fill_price' => $mapped['avg_fill_price'],
                    'submitted_at' => $mapped['submitted_at'],
                    'filled_at' => $mapped['filled_at'],
                    'raw_response_json' => $mapped['raw_response_json'],
                ],
            );
        }
    }

    private function resolveAccount(): ?BrokerAccount
    {
        $query = BrokerAccount::query()
            ->where('broker', BrokerType::COINBASE->value);

        if ($this->brokerAccountId !== null) {
            $query->whereKey($this->brokerAccountId);
        }

        return $query->first();
    }
}
