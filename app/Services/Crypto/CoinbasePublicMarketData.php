<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Support\Mcp\Decimal;
use App\Support\Mcp\ExactJsonNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Read-only Coinbase Advanced Trade public market endpoints. Fixed host and GET paths; no credentials,
 * signing, account, order or mutation call. Contract reviewed against live responses on October 22, 2026.
 */
final class CoinbasePublicMarketData
{
    public const HOST = 'https://api.coinbase.com';

    public const CONTRACT = 'coinbase_advanced_public_market_v1';

    /** Seconds per candle for the stored granularities. */
    public const GRANULARITIES = ['FIVE_MINUTE' => 300, 'ONE_DAY' => 86400];

    public const PRODUCT_PATTERN = '/^[A-Z0-9]{1,12}-[A-Z0-9]{1,12}$/D';

    private const LIMIT = 2 * 1024 * 1024;

    // The endpoint returns at most 350 candles; stay below it.
    private const CANDLES_PER_REQUEST = 300;

    private const FLAGS = ['trading_disabled', 'is_disabled', 'view_only', 'cancel_only', 'limit_only', 'post_only', 'auction_mode'];

    private const SIZES = ['base_increment', 'quote_increment', 'price_increment', 'base_min_size', 'base_max_size', 'quote_min_size', 'quote_max_size'];

    private int $requests = 0;

    public function requests(): int
    {
        return $this->requests;
    }

    /**
     * Products the venue returned, keyed by product ID. Unknown IDs are silently omitted by the venue,
     * so absence means unavailable, never tradable.
     *
     * @param  list<string>  $productIds
     * @return array<string, array<string, mixed>>
     */
    public function products(array $productIds): array
    {
        $this->assertProducts($productIds);
        $query = implode('&', array_map(fn (string $id): string => 'product_ids='.rawurlencode($id), $productIds));
        $body = $this->get('/api/v3/brokerage/market/products?'.$query);
        if (! is_array($body['products'] ?? null) || ! array_is_list($body['products'])) {
            throw new CryptoMarketDataException('invalid_or_unreviewed_response');
        }
        $products = [];
        foreach ($body['products'] as $row) {
            $product = $this->product($row);
            if (! in_array($product['product_id'], $productIds, true) || isset($products[$product['product_id']])) {
                throw new CryptoMarketDataException('invalid_or_unreviewed_response');
            }
            $products[$product['product_id']] = $product;
        }

        return $products;
    }

    /** @return array{bid: string, bid_size: string, ask: string, ask_size: string, source_at: CarbonImmutable} */
    public function bestBidAsk(string $productId, CarbonImmutable $retrievedAt): array
    {
        $this->assertProducts([$productId]);
        $body = $this->get('/api/v3/brokerage/market/product_book?product_id='.rawurlencode($productId).'&limit=1');
        $book = $body['pricebook'] ?? null;
        if (! is_array($book) || ($book['product_id'] ?? null) !== $productId || ! is_string($book['time'] ?? null)) {
            throw new CryptoMarketDataException('invalid_or_unreviewed_response');
        }
        [$bid, $bidSize] = $this->level($book['bids'] ?? null);
        [$ask, $askSize] = $this->level($book['asks'] ?? null);
        if (Decimal::from($ask)->compareTo(Decimal::from($bid)) < 0) {
            throw new CryptoMarketDataException('crossed_book');
        }
        $sourceAt = $this->timestamp($book['time']);
        // A source time ahead of local retrieval beyond clock tolerance is not a usable observation.
        if ($sourceAt->gt($retrievedAt->addSeconds(30))) {
            throw new CryptoMarketDataException('source_time_in_future');
        }

        return ['bid' => $bid, 'bid_size' => $bidSize, 'ask' => $ask, 'ask_size' => $askSize, 'source_at' => $sourceAt];
    }

    /**
     * Closed candles only (end <= $now), ascending by start, within [$from, $now].
     *
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, open: string, high: string, low: string, close: string, volume: string}>
     */
    public function closedCandles(string $productId, string $granularity, CarbonImmutable $from, CarbonImmutable $now): array
    {
        $this->assertProducts([$productId]);
        $seconds = self::GRANULARITIES[$granularity] ?? throw new CryptoMarketDataException('invalid_granularity');
        $start = intdiv($from->getTimestamp(), $seconds) * $seconds;
        $end = $now->getTimestamp();
        $candles = [];
        for ($chunkStart = $start; $chunkStart < $end; $chunkStart += $seconds * self::CANDLES_PER_REQUEST) {
            $chunkEnd = min($end, $chunkStart + $seconds * self::CANDLES_PER_REQUEST);
            $body = $this->get('/api/v3/brokerage/market/products/'.$productId.'/candles?start='.$chunkStart.'&end='.$chunkEnd.'&granularity='.$granularity);
            if (! is_array($body['candles'] ?? null) || ! array_is_list($body['candles'])) {
                throw new CryptoMarketDataException('invalid_or_unreviewed_response');
            }
            foreach ($body['candles'] as $row) {
                $candle = $this->candle($row, $seconds, $chunkStart);
                $key = $candle['starts_at']->getTimestamp();
                // Chunks are half-open; a candle at the boundary belongs to the next chunk.
                if ($key >= $chunkEnd) {
                    continue;
                }
                if (isset($candles[$key])) {
                    throw new CryptoMarketDataException('invalid_or_unreviewed_response');
                }
                if ($candle['ends_at']->getTimestamp() <= $end) {
                    $candles[$key] = $candle;
                }
            }
        }
        ksort($candles);

        return array_values($candles);
    }

    /** @param  list<string>  $productIds */
    private function assertProducts(array $productIds): void
    {
        foreach ($productIds as $id) {
            if (! is_string($id) || ! preg_match(self::PRODUCT_PATTERN, $id)) {
                throw new CryptoMarketDataException('invalid_product');
            }
        }
    }

    private function get(string $pathAndQuery): array
    {
        $this->requests++;
        $exceeded = false;
        try {
            $response = Http::acceptJson()->timeout(15)->connectTimeout(5)
                ->withOptions(['allow_redirects' => false, 'progress' => function ($total, $downloaded) use (&$exceeded): void {
                    if ($downloaded > self::LIMIT) {
                        $exceeded = true;
                        throw new \RuntimeException('Response limit.');
                    }
                }])->get(self::HOST.$pathAndQuery);
        } catch (ConnectionException) {
            throw new CryptoMarketDataException($exceeded ? 'response_limit' : 'transport_unavailable');
        } catch (\Throwable) {
            throw new CryptoMarketDataException($exceeded ? 'response_limit' : 'transport_or_response_failure');
        }
        if (! $response->successful()) {
            // Never keep provider error bodies.
            throw new CryptoMarketDataException(match ($response->status()) {
                404 => 'not_found', 429 => 'rate_limited', default => 'http_failure',
            });
        }
        if (strlen($response->body()) > self::LIMIT) {
            throw new CryptoMarketDataException('response_limit');
        }
        try {
            return ExactJsonNumbers::decode($response->body());
        } catch (\Throwable) {
            throw new CryptoMarketDataException('invalid_or_unreviewed_response');
        }
    }

    private function product(mixed $row): array
    {
        if (! is_array($row)) {
            throw new CryptoMarketDataException('invalid_or_unreviewed_response');
        }
        foreach (['product_id', 'base_currency_id', 'quote_currency_id', 'product_type', 'status'] as $key) {
            if (! is_string($row[$key] ?? null) || $row[$key] === '' || strlen($row[$key]) > 24) {
                throw new CryptoMarketDataException('invalid_or_unreviewed_response');
            }
        }
        if (! preg_match(self::PRODUCT_PATTERN, $row['product_id'])) {
            throw new CryptoMarketDataException('invalid_or_unreviewed_response');
        }
        $product = ['product_id' => $row['product_id'], 'base_currency' => $row['base_currency_id'], 'quote_currency' => $row['quote_currency_id'],
            'product_type' => $row['product_type'], 'status' => $row['status']];
        foreach (self::FLAGS as $key) {
            if (! is_bool($row[$key] ?? null)) {
                throw new CryptoMarketDataException('invalid_or_unreviewed_response');
            }
            $product[$key] = $row[$key];
        }
        foreach (self::SIZES as $key) {
            $product[$key] = $this->decimal($row[$key] ?? null, false);
        }
        // Statistics may be blank; blank is unknown, not zero.
        $product['price'] = $this->decimal($row['price'] ?? '', true);
        $product['volume_24h'] = $this->decimal($row['volume_24h'] ?? '', true);
        $product['quote_volume_24h'] = $this->decimal($row['approximate_quote_24h_volume'] ?? '', true);

        return $product;
    }

    /** @return array{0: string, 1: string} price, size */
    private function level(mixed $levels): array
    {
        if (! is_array($levels) || ! array_is_list($levels) || $levels === [] || ! is_array($levels[0])) {
            throw new CryptoMarketDataException('empty_book');
        }
        $price = $this->decimal($levels[0]['price'] ?? null, false);
        $size = $this->decimal($levels[0]['size'] ?? null, false);
        if (Decimal::from($price)->compareTo(Decimal::from('0')) <= 0) {
            throw new CryptoMarketDataException('invalid_or_unreviewed_response');
        }

        return [$price, $size];
    }

    private function candle(mixed $row, int $seconds, int $from): array
    {
        if (! is_array($row) || ! is_string($row['start'] ?? null) || ! preg_match('/^\d{1,12}$/D', $row['start'])) {
            throw new CryptoMarketDataException('invalid_or_unreviewed_response');
        }
        $start = (int) $row['start'];
        if ($start % $seconds !== 0 || $start < $from) {
            throw new CryptoMarketDataException('invalid_or_unreviewed_response');
        }
        $values = [];
        foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
            $values[$key] = $this->decimal($row[$key] ?? null, false);
        }
        $low = Decimal::from($values['low']);
        $high = Decimal::from($values['high']);
        if ($low->compareTo(Decimal::from('0')) <= 0) {
            throw new CryptoMarketDataException('invalid_or_unreviewed_response');
        }
        foreach (['open', 'close'] as $key) {
            if ($low->compareTo(Decimal::from($values[$key])) > 0 || $high->compareTo(Decimal::from($values[$key])) < 0) {
                throw new CryptoMarketDataException('invalid_or_unreviewed_response');
            }
        }

        return ['starts_at' => CarbonImmutable::createFromTimestampUTC($start), 'ends_at' => CarbonImmutable::createFromTimestampUTC($start + $seconds)] + $values;
    }

    private function decimal(mixed $value, bool $blankIsUnknown): ?string
    {
        if ($blankIsUnknown && $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{1,20}(?:\.\d{1,18})?$/D', $value)) {
            throw new CryptoMarketDataException('invalid_or_unreviewed_response');
        }

        return $value;
    }

    private function timestamp(string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z$/D', $value)) {
            throw new CryptoMarketDataException('invalid_or_unreviewed_response');
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
