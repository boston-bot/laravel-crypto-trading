<?php

namespace App\Jobs;

use App\Enums\BrokerType;
use App\Models\BrokerAccount;
use App\Models\Position;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Coinbase\CoinbaseClient;
use App\Services\Broker\Coinbase\CoinbaseMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCoinbasePositionsJob implements ShouldQueue
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
        $positions = collect($client->getPositions($credential))
            ->filter(function (array $row): bool {
                $currency = strtoupper((string) ($row['currency'] ?? ''));
                $qty = (float) data_get($row, 'available_balance.value', 0.0)
                    + (float) data_get($row, 'hold.value', 0.0);

                return $currency !== 'USD' && $qty > 0;
            })
            ->values();

        $symbols = $positions->map(fn (array $row): string => (string) ($row['currency'] ?? ''))->all();
        $quotes = $symbols === [] ? [] : $client->getBestBidAsk($credential, $symbols);
        $priceBySymbol = collect($quotes)
            ->mapWithKeys(function (array $quote): array {
                $productId = strtoupper((string) ($quote['product_id'] ?? ''));
                $symbol = (string) collect(explode('-', $productId))->first();
                $mid = (float) ($quote['mid_price'] ?? 0.0);

                if ($mid <= 0) {
                    $bid = (float) data_get($quote, 'bids.0.price', 0.0);
                    $ask = (float) data_get($quote, 'asks.0.price', 0.0);
                    $mid = $bid > 0 && $ask > 0 ? ($bid + $ask) / 2 : max($bid, $ask);
                }

                return $symbol !== '' ? [$symbol => $mid] : [];
            })
            ->all();

        foreach ($positions as $rawPosition) {
            $mapped = $mapper->mapPosition($rawPosition, $assetLookup, $priceBySymbol);
            if (($mapped['asset_id'] ?? null) === null) {
                continue;
            }

            Position::query()->updateOrCreate(
                [
                    'broker_account_id' => $account->id,
                    'asset_id' => $mapped['asset_id'],
                ],
                [
                    'quantity' => $mapped['quantity'],
                    'avg_cost' => $mapped['avg_cost'],
                    'market_value' => $mapped['market_value'],
                    'unrealized_pnl' => $mapped['unrealized_pnl'],
                    'snapshot_at' => $mapped['snapshot_at'],
                    'raw_json' => $mapped['raw_json'],
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
