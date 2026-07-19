<?php

namespace App\Services\MarketData;

use App\Models\Asset;
use App\Models\MarketCandle;
use App\Models\MarketCandleRevision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CandleIngestionService
{
    /**
     * @param  array<int, array<string, mixed>>  $candles
     */
    public function ingest(Asset $asset, string $timeframe, array $candles, string $source = 'coinbase'): int
    {
        $intervalSeconds = match ($timeframe) {
            '1h' => 3600,
            '4h' => 14400,
            '1d' => 86400,
            default => throw new InvalidArgumentException("Unsupported candle timeframe [{$timeframe}]."),
        };
        if ($candles === []) {
            return 0;
        }

        $now = now();
        $stored = 0;

        DB::transaction(function () use ($candles, $asset, $timeframe, $source, $now, $intervalSeconds, &$stored): void {
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

                $closeTime = $this->parseTime($candle['close_time'] ?? $candle['candle_close_time'] ?? null);
                $aligned = $openTime->getTimestamp() % $intervalSeconds === 0;
                $correctInterval = $closeTime !== null && ($closeTime->getTimestamp() - $openTime->getTimestamp()) === $intervalSeconds;
                $qualityState = (string) ($candle['quality_state'] ?? 'valid');
                if (! $aligned || ! $correctInterval) {
                    $qualityState = 'invalid';
                }
                $values = [
                    'asset_id' => $asset->id,
                    'symbol' => strtoupper($asset->symbol),
                    'timeframe' => $timeframe,
                    'candle_open_time' => $openTime,
                    'candle_close_time' => $closeTime,
                    'open' => $open,
                    'high' => max($open, $high, $low, $close),
                    'low' => min($open, $high, $low, $close),
                    'close' => $close,
                    'volume' => $this->toFloat($candle['volume'] ?? null),
                    'turnover_usd' => $this->toFloat($candle['turnover_usd'] ?? null),
                    'source' => $source,
                    'ingested_at' => $this->parseTime($candle['ingested_at'] ?? null) ?? $now,
                    'available_at' => $this->parseTime($candle['available_at'] ?? null) ?? $closeTime ?? $now,
                    'is_final' => $qualityState !== 'invalid' && (bool) ($candle['is_final'] ?? ($closeTime?->isPast() ?? false)),
                    'quality_state' => $qualityState,
                    'metadata_json' => is_array($candle['metadata'] ?? null) ? $candle['metadata'] : null,
                ];
                $hash = hash('sha256', json_encode([
                    $values['open'], $values['high'], $values['low'], $values['close'],
                    $values['volume'], $values['turnover_usd'], $values['candle_close_time']?->toIso8601String(),
                ], JSON_THROW_ON_ERROR));

                $existing = MarketCandle::query()
                    ->where('asset_id', $asset->id)->where('timeframe', $timeframe)
                    ->where('candle_open_time', $openTime)->where('source', $source)
                    ->lockForUpdate()->first();

                if ($existing === null) {
                    MarketCandle::query()->create([
                        ...$values,
                        'first_seen_at' => $this->parseTime($candle['first_seen_at'] ?? null) ?? $now,
                        'source_revision' => 1,
                        'content_hash' => $hash,
                    ]);
                    $stored++;

                    continue;
                }

                $sameContent = $existing->content_hash !== null && hash_equals((string) $existing->content_hash, $hash);
                $requiresSemanticUpdate = $existing->quality_state !== $values['quality_state']
                    || (bool) $existing->is_final !== (bool) $values['is_final']
                    || $existing->first_seen_at === null
                    || $existing->candle_close_time?->utc()->getTimestamp() !== $values['candle_close_time']?->utc()->getTimestamp()
                    || (in_array($timeframe, ['4h', '1d'], true) && data_get($existing->metadata_json, 'derived_from') !== '1h');
                if ($sameContent && ! $requiresSemanticUpdate) {
                    continue;
                }

                MarketCandleRevision::query()->create([
                    'market_candle_id' => $existing->id,
                    'revision' => (int) $existing->source_revision,
                    'content_hash' => (string) ($existing->content_hash ?: hash('sha256', json_encode($existing->only(['open', 'high', 'low', 'close', 'volume', 'turnover_usd']), JSON_THROW_ON_ERROR))),
                    'first_seen_at' => $existing->first_seen_at ?? $existing->created_at,
                    'available_at' => $existing->available_at ?? $existing->ingested_at ?? $existing->created_at,
                    'values_json' => $existing->only(['open', 'high', 'low', 'close', 'volume', 'turnover_usd', 'candle_close_time', 'is_final', 'quality_state']),
                    'change_reason' => (string) ($candle['change_reason'] ?? 'source_revision'),
                ]);
                $existing->update([
                    ...$values, 'source_revision' => (int) $existing->source_revision + 1,
                    'content_hash' => $hash, 'available_at' => $now,
                ]);
                $stored++;
            }
        });

        return $stored;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value->utc();
        }

        return CarbonImmutable::parse((string) $value)->utc();
    }
}
