<?php

namespace App\Jobs;

use App\Enums\BrokerType;
use App\Models\Asset;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Coinbase\CoinbaseClient;
use App\Services\MarketData\CandleIngestionService;
use App\Services\MarketData\QuoteSnapshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCoinbaseMarketDataJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $credentialId = null,
        public readonly string $timeframe = '1d',
    ) {
    }

    public function handle(
        CoinbaseClient $client,
        CandleIngestionService $candleIngestionService,
        QuoteSnapshotService $quoteSnapshotService,
        BrokerCredentialResolver $credentialResolver,
    ): void {
        $credential = $credentialResolver->resolve(BrokerType::COINBASE, $this->credentialId);
        if ($credential === null) {
            return;
        }

        $assets = $this->resolveAssets();
        if ($assets->isEmpty()) {
            return;
        }

        $quoteRows = $client->getBestBidAsk($credential, $assets->pluck('symbol')->all());
        $quotesBySymbol = collect($quoteRows)
            ->keyBy(function (array $row): string {
                $productId = strtoupper((string) ($row['product_id'] ?? ''));

                return strtoupper((string) collect(explode('-', $productId))->first());
            });

        foreach ($assets as $asset) {
            $quote = $quotesBySymbol->get(strtoupper($asset->symbol));
            if (is_array($quote)) {
                $quoteSnapshotService->store($asset, $this->normalizeQuote($quote), BrokerType::COINBASE->value);
            }

            $candles = $client->getCandles($credential, $asset->symbol, $this->timeframe);
            $candleIngestionService->ingest($asset, $this->timeframe, $candles, BrokerType::COINBASE->value);
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Asset>
     */
    private function resolveAssets(): \Illuminate\Database\Eloquent\Collection
    {
        $allowedAssets = array_map('strtoupper', (array) config('trading.allowed_assets', []));

        $query = Asset::query()
            ->where('broker', BrokerType::COINBASE->value)
            ->where('is_enabled', true)
            ->where('is_tradable', true)
            ->orderBy('symbol');

        if ($allowedAssets !== []) {
            $query->whereIn('symbol', $allowedAssets);
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function normalizeQuote(array $quote): array
    {
        $bid = $this->toFloat(data_get($quote, 'bids.0.price')) ?? $this->toFloat($quote['bid'] ?? null) ?? 0.0;
        $ask = $this->toFloat(data_get($quote, 'asks.0.price')) ?? $this->toFloat($quote['ask'] ?? null) ?? 0.0;
        $mid = $this->toFloat($quote['mid_price'] ?? null) ?? (($bid + $ask) / 2);

        return [
            'symbol' => (string) ($quote['product_id'] ?? ''),
            'bid_price' => $bid,
            'ask_price' => $ask,
            'last_trade_price' => $this->toFloat($quote['price'] ?? null) ?? $mid,
            'as_of' => now()->toIso8601String(),
            'mid_price' => $mid,
        ];
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
