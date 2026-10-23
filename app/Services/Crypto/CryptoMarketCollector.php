<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crypto C1: one bounded read of Coinbase public market data for the configured products. Each read is an
 * immutable record; a product's metadata, quote and candles are stored together or not at all. Continuous
 * 24/7 cadence, independent of the equity session calendar. No signal, order, credential or account read.
 */
final class CryptoMarketCollector
{
    public const VENUE = 'coinbase';

    public function __construct(private CoinbasePublicMarketData $gateway) {}

    /** @return array{status: string, read_id?: int, products?: int, complete?: int, error?: string|null} */
    public function tick(bool $scheduled = false): array
    {
        if (! config('crypto.market_data_enabled')) {
            return ['status' => 'disabled'];
        }
        $products = config('crypto.coinbase_products');
        if (! is_array($products) || $products === []) {
            return ['status' => 'no_products'];
        }
        if (count($products) > (int) config('crypto.max_products') || count(array_unique($products)) !== count($products)) {
            return ['status' => 'invalid_configuration'];
        }
        foreach ($products as $id) {
            if (! is_string($id) || ! preg_match(CoinbasePublicMarketData::PRODUCT_PATTERN, $id)) {
                return ['status' => 'invalid_configuration'];
            }
        }
        $lock = Cache::lock('crypto-market-collector', 600);
        if (! $lock->get()) {
            return ['status' => 'busy'];
        }
        try {
            return $this->read(array_values($products), $scheduled ? 'scheduled' : 'manual');
        } finally {
            $lock->release();
        }
    }

    /** @param  list<string>  $products */
    private function read(array $products, string $trigger): array
    {
        $readId = DB::table('mcp_crypto_market_reads')->insertGetId([
            'request_id' => (string) Str::uuid(), 'venue' => self::VENUE, 'contract' => CoinbasePublicMarketData::CONTRACT,
            'trigger' => $trigger, 'status' => 'running', 'requested_products' => json_encode($products, JSON_THROW_ON_ERROR),
            'started_at' => self::time(CarbonImmutable::now()),
        ]);
        $complete = 0;
        $inserted = 0;
        $conflicts = 0;
        $error = null;
        try {
            $metadata = $this->gateway->products($products);
            $metadataAt = CarbonImmutable::now();
            foreach ($products as $id) {
                if (! isset($metadata[$id])) {
                    $error ??= 'product_unavailable';

                    continue;
                }
                try {
                    [$added, $differing] = $this->product($readId, $metadata[$id], $metadataAt);
                    $inserted += $added;
                    $conflicts += $differing;
                    $complete++;
                } catch (CryptoMarketDataException $failure) {
                    $error ??= $failure->reason;
                }
            }
        } catch (CryptoMarketDataException $failure) {
            $error = $failure->reason;
        }
        $status = match (true) {
            $complete === count($products) => 'complete',
            $complete > 0 => 'partial',
            default => 'failed',
        };
        DB::table('mcp_crypto_market_reads')->where('id', $readId)->update([
            'status' => $status, 'error' => $status === 'complete' ? null : $error, 'products_complete' => $complete,
            'requests' => $this->gateway->requests(), 'candles_inserted' => $inserted, 'candle_conflicts' => $conflicts,
            'finished_at' => self::time(CarbonImmutable::now()),
        ]);

        return ['status' => $status, 'read_id' => $readId, 'products' => count($products), 'complete' => $complete, 'error' => $status === 'complete' ? null : $error];
    }

    /** @return array{0: int, 1: int} candles inserted, stored candles that a later observation contradicts */
    private function product(int $readId, array $product, CarbonImmutable $metadataAt): array
    {
        $id = $product['product_id'];
        $quoteAt = CarbonImmutable::now();
        $quote = $this->gateway->bestBidAsk($id, $quoteAt);
        $now = CarbonImmutable::now();
        $candles = ['FIVE_MINUTE' => $this->gateway->closedCandles($id, 'FIVE_MINUTE', $now->subMinutes((int) config('crypto.intraday_lookback_minutes')), $now)];
        $dailyFrom = $this->dailyFrom($id, $now);
        if ($dailyFrom !== null) {
            $candles['ONE_DAY'] = $this->gateway->closedCandles($id, 'ONE_DAY', $dailyFrom, $now);
        }
        $retrievedAt = CarbonImmutable::now();

        return DB::transaction(function () use ($readId, $product, $metadataAt, $quote, $quoteAt, $candles, $retrievedAt): array {
            DB::table('mcp_crypto_product_snapshots')->insert(['mcp_crypto_market_read_id' => $readId, 'venue' => self::VENUE,
                'retrieved_at' => self::time($metadataAt)] + $product);
            DB::table('mcp_crypto_quotes')->insert(['mcp_crypto_market_read_id' => $readId, 'venue' => self::VENUE, 'product_id' => $product['product_id'],
                'bid' => $quote['bid'], 'bid_size' => $quote['bid_size'], 'ask' => $quote['ask'], 'ask_size' => $quote['ask_size'],
                'source_at' => self::time($quote['source_at']), 'retrieved_at' => self::time($quoteAt)]);
            $inserted = 0;
            $conflicts = 0;
            foreach ($candles as $granularity => $rows) {
                [$added, $differing] = $this->candles($readId, $product['product_id'], $granularity, $rows, $retrievedAt);
                $inserted += $added;
                $conflicts += $differing;
            }

            return [$inserted, $conflicts];
        });
    }

    /** First observation is kept; a later differing observation is counted on its read, never applied. */
    private function candles(int $readId, string $productId, string $granularity, array $rows, CarbonImmutable $retrievedAt): array
    {
        if ($rows === []) {
            return [0, 0];
        }
        $existing = [];
        $stored = DB::table('mcp_crypto_candles')->where(['venue' => self::VENUE, 'product_id' => $productId, 'granularity' => $granularity])
            ->whereBetween('starts_at', [self::time($rows[0]['starts_at']), self::time($rows[array_key_last($rows)]['starts_at'])])->get();
        foreach ($stored as $row) {
            $existing[CarbonImmutable::parse($row->starts_at)->getTimestamp()] = $row;
        }
        $inserted = 0;
        $conflicts = 0;
        foreach ($rows as $candle) {
            $prior = $existing[$candle['starts_at']->getTimestamp()] ?? null;
            if ($prior !== null) {
                foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
                    if (Decimal::from(self::plain((string) $prior->{$key}))->compareTo(Decimal::from($candle[$key])) !== 0) {
                        $conflicts++;
                        break;
                    }
                }

                continue;
            }
            $inserted += DB::table('mcp_crypto_candles')->insertOrIgnore(['venue' => self::VENUE, 'product_id' => $productId, 'granularity' => $granularity,
                'starts_at' => self::time($candle['starts_at']), 'ends_at' => self::time($candle['ends_at']),
                'open' => $candle['open'], 'high' => $candle['high'], 'low' => $candle['low'], 'close' => $candle['close'], 'volume' => $candle['volume'],
                'mcp_crypto_market_read_id' => $readId, 'retrieved_at' => self::time($retrievedAt)]);
        }

        return [$inserted, $conflicts];
    }

    /** Daily candles are re-read only when yesterday's UTC day is missing; null means current. */
    private function dailyFrom(string $productId, CarbonImmutable $now): ?CarbonImmutable
    {
        $today = $now->utc()->startOfDay();
        $latest = DB::table('mcp_crypto_candles')->where(['venue' => self::VENUE, 'product_id' => $productId, 'granularity' => 'ONE_DAY'])->max('ends_at');
        $earliest = $today->subDays((int) config('crypto.daily_lookback_days'));
        if ($latest === null) {
            return $earliest;
        }
        $latest = CarbonImmutable::parse($latest)->utc();

        return $latest->gte($today) ? null : ($latest->lt($earliest) ? $earliest : $latest);
    }

    /** UTC with microseconds: sorts consistently as text (SQLite) and keeps its offset (PostgreSQL). */
    public static function time(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d H:i:s.uP');
    }

    /** PostgreSQL numeric values come back with trailing zeros; SQLite may return integers. */
    public static function plain(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
