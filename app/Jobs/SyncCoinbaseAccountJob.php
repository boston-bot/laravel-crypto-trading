<?php

namespace App\Jobs;

use App\Enums\BrokerType;
use App\Models\BrokerAccount;
use App\Models\BrokerCredential;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Coinbase\CoinbaseClient;
use App\Services\Broker\Coinbase\CoinbaseMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class SyncCoinbaseAccountJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $credentialId = null,
    ) {}

    public function handle(
        CoinbaseClient $client,
        CoinbaseMapper $mapper,
        BrokerCredentialResolver $credentialResolver,
    ): void {
        $credential = $credentialResolver->resolve(BrokerType::COINBASE, $this->credentialId);
        if ($credential === null) {
            return;
        }

        $accounts = collect($client->getAccounts($credential));
        if ($accounts->isEmpty()) {
            return;
        }

        $primary = $this->selectPrimaryAccount($accounts);
        $mapped = $mapper->mapAccount($primary);
        $equity = $this->estimateEquityUsd($accounts, $client, $credential);

        BrokerAccount::query()->updateOrCreate(
            [
                'broker' => BrokerType::COINBASE->value,
                'external_account_id' => $mapped['external_account_id'],
            ],
            [
                'broker_credential_id' => $credential->exists ? $credential->id : null,
                'account_type' => $mapped['account_type'],
                'currency' => 'USD',
                'buying_power' => $mapped['buying_power'],
                'cash_balance' => $mapped['cash_balance'],
                'equity' => $equity,
                'status' => $mapped['status'],
                'snapshot_at' => now(),
                'raw_json' => [
                    'primary_account' => $primary,
                    'accounts' => $accounts->all(),
                ],
            ],
        );

        if ($credential->exists) {
            $credential->forceFill([
                'last_verified_at' => now(),
            ])->save();
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    private function selectPrimaryAccount(Collection $accounts): array
    {
        $usd = $accounts->first(fn (array $account): bool => strtoupper((string) ($account['currency'] ?? '')) === 'USD');

        if (is_array($usd)) {
            return $usd;
        }

        return (array) $accounts->first();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accounts
     */
    private function estimateEquityUsd(Collection $accounts, CoinbaseClient $client, BrokerCredential $credential): float
    {
        $positions = $accounts
            ->filter(fn (array $row): bool => strtoupper((string) ($row['currency'] ?? '')) !== 'USD')
            ->values();

        $symbols = $positions
            ->map(fn (array $row): string => (string) ($row['currency'] ?? ''))
            ->filter()
            ->all();

        $quotes = $symbols === [] ? [] : $client->getBestBidAsk($credential, $symbols);
        $priceBySymbol = collect($quotes)
            ->mapWithKeys(function (array $quote): array {
                $productId = strtoupper((string) ($quote['product_id'] ?? ''));
                $symbol = (string) collect(explode('-', $productId))->first();
                $price = (float) ($quote['mid_price'] ?? $quote['price'] ?? 0.0);
                if ($price <= 0) {
                    $bid = (float) data_get($quote, 'bids.0.price', 0.0);
                    $ask = (float) data_get($quote, 'asks.0.price', 0.0);
                    $price = $bid > 0 && $ask > 0 ? ($bid + $ask) / 2 : max($bid, $ask);
                }

                return $symbol !== '' ? [$symbol => $price] : [];
            })
            ->all();

        $cash = $accounts
            ->filter(fn (array $row): bool => strtoupper((string) ($row['currency'] ?? '')) === 'USD')
            ->sum(fn (array $row): float => (float) data_get($row, 'available_balance.value', 0.0)
                + (float) data_get($row, 'hold.value', 0.0));

        $inventory = $positions->sum(function (array $row) use ($priceBySymbol): float {
            $symbol = strtoupper((string) ($row['currency'] ?? ''));
            $qty = (float) data_get($row, 'available_balance.value', 0.0)
                + (float) data_get($row, 'hold.value', 0.0);

            return $qty * (float) ($priceBySymbol[$symbol] ?? 0.0);
        });

        return round($cash + $inventory, 8);
    }
}
