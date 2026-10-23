<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Deterministic stand-in for Coinbase's public market endpoints, shaped like the live responses reviewed on
 * October 22, 2026: newest-first candles including the open one, unknown product IDs silently omitted.
 */
final class CoinbasePublicFake
{
    /** @var array<string, int|null> product ID => HTTP status to fail its book with, or null */
    public array $bookFailures = [];

    public ?int $productsFailure = null;

    /** Added to every candle close, to simulate a revised observation. */
    public string $closeSuffix = '';

    /** @var list<string> */
    public array $paths = [];

    /** @param  list<string>  $known products the venue lists */
    public function __construct(public array $known = ['BTC-USD', 'ETH-USD']) {}

    public static function install(array $known = ['BTC-USD', 'ETH-USD']): self
    {
        $fake = new self($known);
        Http::fake(fn (Request $request) => $fake->respond($request));

        return $fake;
    }

    public function respond(Request $request)
    {
        $url = parse_url($request->url());
        $path = $url['path'];
        $this->paths[] = $path;
        parse_str(str_replace('product_ids=', 'product_ids[]=', $url['query'] ?? ''), $query);
        if ($path === '/api/v3/brokerage/market/products') {
            if ($this->productsFailure !== null) {
                return Http::response(['error' => 'secret provider detail'], $this->productsFailure);
            }
            $products = array_values(array_map(fn (string $id) => self::productRow($id), array_intersect($query['product_ids'] ?? [], $this->known)));

            return Http::response(['products' => $products, 'num_products' => (string) count($products), 'pagination' => []]);
        }
        if ($path === '/api/v3/brokerage/market/product_book') {
            $id = $query['product_id'];
            if (($status = $this->bookFailures[$id] ?? null) !== null) {
                return Http::response(['error' => 'secret provider detail'], $status);
            }

            return Http::response(['pricebook' => ['product_id' => $id, 'bids' => [['price' => '100.25', 'size' => '0.5']],
                'asks' => [['price' => '100.26', 'size' => '0.75']], 'time' => now()->utc()->subSeconds(2)->format('Y-m-d\TH:i:s.u\Z')],
                'last' => '100.255', 'mid_market' => '100.255', 'spread_bps' => '0.99', 'spread_absolute' => '0.01']);
        }
        if (preg_match('#^/api/v3/brokerage/market/products/([A-Z0-9-]+)/candles$#', $path, $match)) {
            if (! in_array($match[1], $this->known, true)) {
                return Http::response(['error' => 'NOT_FOUND'], 404);
            }
            $seconds = ['FIVE_MINUTE' => 300, 'ONE_DAY' => 86400][$query['granularity']];
            $candles = [];
            // Includes a candle starting at `end` and the still-open one, as the venue does.
            for ($start = intdiv((int) $query['start'], $seconds) * $seconds; $start <= (int) $query['end']; $start += $seconds) {
                $candles[] = ['start' => (string) $start, 'low' => '99.5', 'high' => '101.5', 'open' => '100', 'close' => '100.5'.$this->closeSuffix, 'volume' => '12.25'];
            }

            return Http::response(['candles' => array_reverse($candles)]);
        }

        return Http::response([], 500);
    }

    public function count(string $fragment): int
    {
        return count(array_filter($this->paths, fn (string $path) => str_contains($path, $fragment)));
    }

    public static function productRow(string $id): array
    {
        [$base, $quote] = explode('-', $id);

        return ['product_id' => $id, 'price' => '100.25', 'volume_24h' => '7325.15120441', 'base_increment' => '0.00000001', 'quote_increment' => '0.01',
            'quote_min_size' => '1', 'quote_max_size' => '150000000', 'base_min_size' => '0.00000001', 'base_max_size' => '3400', 'is_disabled' => false,
            'status' => 'online', 'cancel_only' => false, 'limit_only' => false, 'post_only' => false, 'trading_disabled' => false, 'auction_mode' => false,
            'product_type' => 'SPOT', 'quote_currency_id' => $quote, 'base_currency_id' => $base, 'view_only' => false, 'price_increment' => '0.01',
            'approximate_quote_24h_volume' => '', 'best_bid_price' => '', 'best_ask_price' => '', 'unreviewed_extra' => ['ignored' => true]];
    }
}
