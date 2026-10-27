<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Stored-data reads for later crypto milestones. Stale or missing data is unknown (null), never a guessed value. */
final class CryptoMarketData
{
    /** @return array{product_id: string, bid: string, bid_size: string, ask: string, ask_size: string, source_at: CarbonImmutable, retrieved_at: CarbonImmutable, age_seconds: int}|null */
    public function freshQuote(string $productId, ?CarbonImmutable $at = null): ?array
    {
        $at ??= CarbonImmutable::now();
        $row = DB::table('mcp_crypto_quotes')->where(['venue' => CryptoMarketCollector::VENUE, 'product_id' => $productId])
            ->where('source_at', '<=', CryptoMarketCollector::time($at))->orderByDesc('source_at')->first();
        if ($row === null) {
            return null;
        }
        $sourceAt = CarbonImmutable::parse($row->source_at)->utc();
        $age = (int) $sourceAt->diffInSeconds($at, true);
        if ($age > (int) config('crypto.quote_max_age_seconds')) {
            return null;
        }

        return ['product_id' => $productId, 'bid' => CryptoMarketCollector::plain((string) $row->bid), 'bid_size' => CryptoMarketCollector::plain((string) $row->bid_size),
            'ask' => CryptoMarketCollector::plain((string) $row->ask), 'ask_size' => CryptoMarketCollector::plain((string) $row->ask_size),
            'source_at' => $sourceAt, 'retrieved_at' => CarbonImmutable::parse($row->retrieved_at)->utc(), 'age_seconds' => $age];
    }

    /** @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, open: string, high: string, low: string, close: string, volume: string}> */
    public function candles(string $productId, string $granularity, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('mcp_crypto_candles')->where(['venue' => CryptoMarketCollector::VENUE, 'product_id' => $productId, 'granularity' => $granularity])
            ->where('starts_at', '>=', CryptoMarketCollector::time($from))->where('ends_at', '<=', CryptoMarketCollector::time($to))
            ->orderBy('starts_at')->get()
            ->map(fn (object $row): array => ['starts_at' => CarbonImmutable::parse($row->starts_at)->utc(), 'ends_at' => CarbonImmutable::parse($row->ends_at)->utc()]
                + array_map(fn ($value) => CryptoMarketCollector::plain((string) $value), ['open' => $row->open, 'high' => $row->high, 'low' => $row->low, 'close' => $row->close, 'volume' => $row->volume]))
            ->all();
    }

    /** @return array{status: string, detail: array} health check: disabled, ok, warning or critical */
    public function health(?CarbonImmutable $at = null): array
    {
        if (! config('crypto.market_data_enabled')) {
            return ['status' => 'disabled', 'detail' => []];
        }
        $at ??= CarbonImmutable::now();
        $limit = (int) config('crypto.collection_max_age_minutes');
        // A history backfill is not evidence that the five-minute collection is running.
        $complete = DB::table('mcp_crypto_market_reads')->where(['venue' => CryptoMarketCollector::VENUE, 'status' => 'complete'])->where('trigger', '!=', 'backfill')->max('finished_at');
        $latest = DB::table('mcp_crypto_market_reads')->where('venue', CryptoMarketCollector::VENUE)->where('trigger', '!=', 'backfill')->whereNotNull('finished_at')->orderByDesc('finished_at')->orderByDesc('id')->first(['status', 'error']);
        $age = $complete === null ? null : (int) CarbonImmutable::parse($complete)->diffInMinutes($at, true);
        $status = match (true) {
            $age === null || $age > $limit => 'critical',
            $latest !== null && $latest->status !== 'complete' => 'warning',
            default => 'ok',
        };

        return ['status' => $status, 'detail' => ['complete_age_minutes' => $age, 'limit_minutes' => $limit, 'latest_status' => $latest?->status, 'latest_error' => $latest?->error]];
    }
}
