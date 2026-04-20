<?php

namespace App\Services\MarketData;

use App\Models\Asset;
use App\Models\MarketCandle;
use Carbon\CarbonImmutable;

class CandleIngestionService
{
    /**
     * @param  array<int, array<string, mixed>>  $candles
     */
    public function ingest(Asset $asset, string $timeframe, array $candles, string $source = 'coinbase'): int
    {
        if ($candles === []) {
            return 0;
        }

        $rows = [];
        $now = now();

        foreach ($candles as $candle) {
            $openTime = $this->parseTime($candle['open_time'] ?? $candle['candle_open_time'] ?? null);
            if ($openTime === null) {
                continue;
            }

            $close = $this->toFloat($candle['close'] ?? null);
            $open = $this->toFloat($candle['open'] ?? $close);
            $high = $this->toFloat($candle['high'] ?? $close);
            $low = $this->toFloat($candle['low'] ?? $close);

            if ($close <= 0 || $open <= 0 || $high <= 0 || $low <= 0) {
                continue;
            }

            $rows[] = [
                'asset_id' => $asset->id,
                'symbol' => strtoupper($asset->symbol),
                'timeframe' => $timeframe,
                'candle_open_time' => $openTime,
                'candle_close_time' => $this->parseTime($candle['close_time'] ?? $candle['candle_close_time'] ?? null),
                'open' => $open,
                'high' => max($open, $high, $low, $close),
                'low' => min($open, $high, $low, $close),
                'close' => $close,
                'volume' => $this->toFloat($candle['volume'] ?? null),
                'turnover_usd' => $this->toFloat($candle['turnover_usd'] ?? null),
                'source' => $source,
                'ingested_at' => $this->parseTime($candle['ingested_at'] ?? null) ?? $now,
                'metadata_json' => is_array($candle['metadata'] ?? null) ? json_encode($candle['metadata']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        MarketCandle::query()->upsert(
            $rows,
            ['asset_id', 'timeframe', 'candle_open_time', 'source'],
            [
                'candle_close_time',
                'open',
                'high',
                'low',
                'close',
                'volume',
                'turnover_usd',
                'ingested_at',
                'metadata_json',
                'updated_at',
            ]
        );

        return count($rows);
    }

    /**
     * @param  mixed  $value
     */
    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param  mixed  $value
     */
    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        return CarbonImmutable::parse((string) $value);
    }
}
