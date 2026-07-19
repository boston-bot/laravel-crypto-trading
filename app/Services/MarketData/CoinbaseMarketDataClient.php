<?php

namespace App\Services\MarketData;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;
use RuntimeException;

class CoinbaseMarketDataClient
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCandles(string $symbol, string $timeframe = '1d', int $limit = 300): array
    {
        $product = $this->normalizeSymbol($symbol);
        [$requestGranularity, $bucketSize] = $this->granularityPlanForTimeframe($timeframe);

        $limit = max(10, min(300, $limit));
        $requestLimit = $bucketSize > $requestGranularity
            ? min(300, ($limit * (int) ($bucketSize / $requestGranularity)) + 8)
            : $limit;

        $end = now()->utc();
        $start = $end->copy()->subSeconds($requestGranularity * $requestLimit);

        $url = rtrim((string) config('services.coinbase.exchange_base_url', 'https://api.exchange.coinbase.com'), '/').'/products/'.$product.'/candles';

        $response = $this->http
            ->acceptJson()
            ->timeout(10)
            ->get($url, [
                'granularity' => $requestGranularity,
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Coinbase candle request failed ('.$response->status().'): '.$response->body());
        }

        $rows = $response->json();
        if (! is_array($rows)) {
            return [];
        }

        $normalized = collect($rows)
            ->filter(fn ($row): bool => is_array($row) && count($row) >= 6)
            ->map(function (array $row) use ($requestGranularity): array {
                $openTime = (int) $row[0];

                return [
                    'open_time' => now()->createFromTimestampUTC($openTime)->toIso8601String(),
                    'close_time' => now()->createFromTimestampUTC($openTime + $requestGranularity)->toIso8601String(),
                    'low' => (float) $row[1],
                    'high' => (float) $row[2],
                    'open' => (float) $row[3],
                    'close' => (float) $row[4],
                    'volume' => (float) $row[5],
                    'metadata' => [
                        'provider' => 'coinbase',
                        'raw' => $row,
                    ],
                ];
            })
            ->sortBy('open_time')
            ->values()
            ->all();

        if ($bucketSize > $requestGranularity) {
            return $this->aggregateCandles($normalized, $bucketSize, $limit);
        }

        return array_slice($normalized, -$limit);
    }

    /**
     * Fetch a bounded half-open UTC range: [start, end).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHourlyCandlesRange(string $symbol, CarbonInterface $start, CarbonInterface $end): array
    {
        $product = $this->normalizeSymbol($symbol);
        $cursor = CarbonImmutable::instance($start)->utc()->startOfHour();
        $rangeEnd = CarbonImmutable::instance($end)->utc()->startOfHour();
        if ($rangeEnd->lessThanOrEqualTo($cursor)) {
            return [];
        }

        $url = rtrim((string) config('services.coinbase.exchange_base_url', 'https://api.exchange.coinbase.com'), '/').'/products/'.$product.'/candles';
        $rowsByOpen = [];

        while ($cursor->lessThan($rangeEnd)) {
            // The API treats both boundaries as inclusive in some responses.
            $chunkEnd = $cursor->addHours(299);
            if ($chunkEnd->greaterThan($rangeEnd)) {
                $chunkEnd = $rangeEnd;
            }
            $rowsByOpen += $this->fetchHourlyRows($url, $cursor, $chunkEnd);

            $cursor = $chunkEnd;
        }

        // Successful Coinbase responses can be sparse. Retry missing hours in
        // narrow windows before the repair service records a genuine source gap.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $missing = $this->missingHourlyTimestamps($rowsByOpen, CarbonImmutable::instance($start)->utc()->startOfHour(), $rangeEnd);
            if ($missing === []) {
                break;
            }

            foreach ($missing as $timestamp) {
                $missingOpen = CarbonImmutable::createFromTimestampUTC($timestamp);
                $retryStart = $missingOpen->subHour();
                if ($retryStart->lessThan($start)) {
                    $retryStart = CarbonImmutable::instance($start)->utc()->startOfHour();
                }
                $retryEnd = $missingOpen->addHours(2);
                if ($retryEnd->greaterThan($rangeEnd)) {
                    $retryEnd = $rangeEnd;
                }
                $rowsByOpen += $this->fetchHourlyRows($url, $retryStart, $retryEnd);
            }
        }

        ksort($rowsByOpen);

        return array_values($rowsByOpen);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchHourlyRows(string $url, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $response = $this->http
            ->acceptJson()
            ->timeout(20)
            ->retry([500, 1000, 2000], throw: false)
            ->get($url, [
                'granularity' => 3600,
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Coinbase hourly repair request failed ('.$response->status().'): '.$response->body());
        }

        $rows = [];
        foreach ((array) $response->json() as $row) {
            if (! is_array($row) || count($row) < 6) {
                continue;
            }
            $openTime = CarbonImmutable::createFromTimestampUTC((int) $row[0]);
            if ($openTime->lessThan($start) || ! $openTime->lessThan($end)) {
                continue;
            }

            $rows[$openTime->getTimestamp()] = [
                'open_time' => $openTime->toIso8601String(),
                'close_time' => $openTime->addHour()->toIso8601String(),
                'low' => (float) $row[1],
                'high' => (float) $row[2],
                'open' => (float) $row[3],
                'close' => (float) $row[4],
                'volume' => (float) $row[5],
                'available_at' => $openTime->addHour()->toIso8601String(),
                'is_final' => $openTime->addHour()->isPast(),
                'quality_state' => 'valid',
                'metadata' => [
                    'provider' => 'coinbase',
                    'acquisition_path' => 'public_exchange_repair',
                    'raw' => $row,
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rowsByOpen
     * @return array<int, int>
     */
    private function missingHourlyTimestamps(array $rowsByOpen, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $missing = [];
        for ($cursor = $start; $cursor->lessThan($end); $cursor = $cursor->addHour()) {
            if (! isset($rowsByOpen[$cursor->getTimestamp()])) {
                $missing[] = $cursor->getTimestamp();
            }
        }

        return $missing;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function granularityPlanForTimeframe(string $timeframe): array
    {
        return match ($timeframe) {
            '1h' => [3600, 3600],
            // Coinbase Exchange API does not support 14400 directly.
            '4h' => [3600, 14400],
            '1d' => [86400, 86400],
            default => throw new InvalidArgumentException("Unsupported Coinbase candle timeframe [{$timeframe}]."),
        };
    }

    private function normalizeSymbol(string $symbol): string
    {
        $upper = strtoupper(trim($symbol));
        if ($upper === '') {
            return $upper;
        }

        if (str_ends_with($upper, '-USD')) {
            return $upper;
        }

        return $upper.'-USD';
    }

    /**
     * @param  array<int, array<string, mixed>>  $candles
     * @return array<int, array<string, mixed>>
     */
    private function aggregateCandles(array $candles, int $bucketSize, int $limit): array
    {
        $buckets = [];

        foreach ($candles as $candle) {
            $openTime = strtotime((string) ($candle['open_time'] ?? ''));
            if ($openTime === false) {
                continue;
            }

            $bucketStart = (int) (floor($openTime / $bucketSize) * $bucketSize);
            $key = (string) $bucketStart;

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'open_time' => now()->createFromTimestampUTC($bucketStart)->toIso8601String(),
                    'close_time' => now()->createFromTimestampUTC($bucketStart + $bucketSize)->toIso8601String(),
                    'open' => (float) $candle['open'],
                    'high' => (float) $candle['high'],
                    'low' => (float) $candle['low'],
                    'close' => (float) $candle['close'],
                    'volume' => (float) $candle['volume'],
                    'metadata' => [
                        'provider' => 'coinbase',
                        'aggregated' => true,
                        'bucket_seconds' => $bucketSize,
                    ],
                ];

                continue;
            }

            $buckets[$key]['high'] = max((float) $buckets[$key]['high'], (float) $candle['high']);
            $buckets[$key]['low'] = min((float) $buckets[$key]['low'], (float) $candle['low']);
            $buckets[$key]['close'] = (float) $candle['close'];
            $buckets[$key]['volume'] = (float) $buckets[$key]['volume'] + (float) $candle['volume'];
        }

        ksort($buckets);

        return array_slice(array_values($buckets), -$limit);
    }
}
