<?php

namespace App\Jobs;

use App\Enums\BrokerType;
use App\Models\Asset;
use App\Services\Broker\BrokerException;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Robinhood\RobinhoodClient;
use App\Services\MarketData\CandleIngestionService;
use App\Services\MarketData\CoinbaseMarketDataClient;
use App\Services\MarketData\QuoteSnapshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncRobinhoodMarketDataJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $credentialId = null,
        public readonly string $timeframe = '1d',
    ) {
    }

    public function handle(
        RobinhoodClient $client,
        CandleIngestionService $candleIngestionService,
        QuoteSnapshotService $quoteSnapshotService,
        CoinbaseMarketDataClient $coinbaseMarketDataClient,
        BrokerCredentialResolver $credentialResolver,
    ): void {
        $credential = $credentialResolver->resolve(BrokerType::ROBINHOOD, $this->credentialId);
        if ($credential === null) {
            return;
        }

        $assets = $this->resolveAssets();
        if ($assets->isEmpty()) {
            return;
        }

        $quoteRows = $client->getBestBidAsk($credential, $assets->pluck('symbol')->all());
        $quotesBySymbol = collect($quoteRows)
            ->filter(fn (array $row): bool => ($row['symbol'] ?? $row['asset_code'] ?? null) !== null)
            ->keyBy(function (array $row): string {
                $symbol = (string) ($row['symbol'] ?? $row['asset_code'] ?? '');

                return strtoupper(str_replace(['-USD', '/USD'], '', $symbol));
            });

        [$interval, $span] = $this->resolveRobinhoodCandleParams($this->timeframe);

        foreach ($assets as $asset) {
            $quote = $quotesBySymbol->get(strtoupper($asset->symbol));
            if (is_array($quote)) {
                $quoteSnapshotService->store($asset, $quote, BrokerType::ROBINHOOD->value);
            }

            $candles = [];
            $candleSource = BrokerType::ROBINHOOD->value;

            try {
                $candles = $client->getCandles($credential, $asset->symbol, $interval, $span);
            } catch (BrokerException $exception) {
                if (str_contains($exception->getMessage(), '(404)') || str_contains($exception->getMessage(), 'endpoint not found')) {
                    logger()->warning('Skipping candle sync due to unsupported Robinhood candle endpoint.', [
                        'asset' => $asset->symbol,
                        'timeframe' => $this->timeframe,
                        'message' => $exception->getMessage(),
                    ]);
                } else {
                    throw $exception;
                }
            }

            if ($candles === []) {
                $candles = $coinbaseMarketDataClient->getCandles($asset->symbol, $this->timeframe);
                $candleSource = 'coinbase';
            }

            $normalizedCandles = collect($candles)
                ->map(fn (array $row): array => $this->normalizeCandle($row))
                ->filter(fn (array $row): bool => ($row['open_time'] ?? null) !== null)
                ->values()
                ->all();

            $candleIngestionService->ingest($asset, $this->timeframe, $normalizedCandles, $candleSource);
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Asset>
     */
    private function resolveAssets(): \Illuminate\Database\Eloquent\Collection
    {
        $allowedAssets = array_map('strtoupper', (array) config('trading.allowed_assets', []));

        $query = Asset::query()
            ->where('broker', BrokerType::ROBINHOOD->value)
            ->where('is_enabled', true)
            ->where('is_tradable', true)
            ->orderBy('symbol');

        if ($allowedAssets !== []) {
            $query->whereIn('symbol', $allowedAssets);
        }

        return $query->get();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveRobinhoodCandleParams(string $timeframe): array
    {
        return match ($timeframe) {
            '4h' => ['hour', (string) config('trading.market_data.span_4h', '3month')],
            default => ['day', (string) config('trading.market_data.span_1d', 'year')],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeCandle(array $row): array
    {
        $open = (float) ($row['open_price'] ?? $row['open'] ?? 0.0);
        $high = (float) ($row['high_price'] ?? $row['high'] ?? 0.0);
        $low = (float) ($row['low_price'] ?? $row['low'] ?? 0.0);
        $close = (float) ($row['close_price'] ?? $row['close'] ?? 0.0);

        return [
            'open_time' => $row['begins_at'] ?? $row['open_time'] ?? $row['timestamp'] ?? null,
            'close_time' => $row['ends_at'] ?? $row['close_time'] ?? null,
            'open' => $open,
            'high' => max($open, $high, $low, $close),
            'low' => min($open, $high, $low, $close),
            'close' => $close,
            'volume' => (float) ($row['volume'] ?? 0.0),
            'turnover_usd' => (float) ($row['notional'] ?? $row['turnover_usd'] ?? 0.0),
            'metadata' => $row,
        ];
    }
}
