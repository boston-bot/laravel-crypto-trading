<?php

namespace Tests\Feature\Crypto;

use App\Services\Crypto\CoinbasePublicMarketData;
use App\Services\Crypto\CryptoMarketDataException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\CoinbasePublicFake;
use Tests\TestCase;

class CoinbasePublicMarketDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22T23:44:29Z'));
    }

    public function test_products_are_one_unauthenticated_get_and_unknown_ids_are_absent(): void
    {
        CoinbasePublicFake::install();
        $products = (new CoinbasePublicMarketData)->products(['BTC-USD', 'ETH-USD', 'NOPE-USD']);

        $this->assertSame(['BTC-USD', 'ETH-USD'], array_keys($products));
        $this->assertSame(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD', 'product_type' => 'SPOT', 'status' => 'online'],
            array_slice($products['BTC-USD'], 0, 5));
        $this->assertSame('0.00000001', $products['BTC-USD']['base_increment']);
        $this->assertFalse($products['BTC-USD']['trading_disabled']);
        $this->assertNull($products['BTC-USD']['quote_volume_24h'], 'Blank statistics are unknown, not zero');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && ! $request->hasHeader('Authorization')
            && str_starts_with($request->url(), 'https://api.coinbase.com/api/v3/brokerage/market/products?product_ids=BTC-USD&product_ids=ETH-USD&product_ids=NOPE-USD'));
    }

    public function test_unrequested_or_malformed_products_are_rejected(): void
    {
        $malformed = CoinbasePublicFake::productRow('BTC-USD');
        $malformed['trading_disabled'] = 'false';
        $duplicate = CoinbasePublicFake::productRow('BTC-USD');
        Http::fake(['*' => Http::sequence()
            ->push(['products' => [CoinbasePublicFake::productRow('SOL-USD')]])
            ->push(['products' => [$malformed]])
            ->push(['products' => [$duplicate, $duplicate]])]);
        foreach (range(1, 3) as $case) {
            $this->assertReason('invalid_or_unreviewed_response', fn () => (new CoinbasePublicMarketData)->products(['BTC-USD']));
        }
        Http::assertSentCount(3);
    }

    public function test_invalid_product_ids_are_refused_before_any_request(): void
    {
        Http::fake();
        foreach (['btc-usd', 'BTC/USD', '../orders', 'BTC-USD?x=1'] as $id) {
            $this->assertReason('invalid_product', fn () => (new CoinbasePublicMarketData)->bestBidAsk($id, CarbonImmutable::now()));
        }
        Http::assertNothingSent();
    }

    public function test_best_bid_ask_keeps_exact_decimals_and_source_time(): void
    {
        CoinbasePublicFake::install();
        $quote = (new CoinbasePublicMarketData)->bestBidAsk('BTC-USD', CarbonImmutable::now());

        $this->assertSame(['100.25', '0.5', '100.26', '0.75'], [$quote['bid'], $quote['bid_size'], $quote['ask'], $quote['ask_size']]);
        $this->assertSame('2026-10-22T23:44:27.000000Z', $quote['source_at']->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function test_crossed_empty_future_and_failed_books_are_not_observations(): void
    {
        $book = fn (array $overrides) => Http::response(['pricebook' => array_replace(['product_id' => 'BTC-USD', 'bids' => [['price' => '100', 'size' => '1']],
            'asks' => [['price' => '101', 'size' => '1']], 'time' => '2026-10-22T23:44:28Z'], $overrides)]);
        $cases = [
            'crossed_book' => $book(['asks' => [['price' => '99.99', 'size' => '1']]]),
            'empty_book' => $book(['bids' => []]),
            'source_time_in_future' => $book(['time' => '2026-10-22T23:46:00Z']),
            'invalid_or_unreviewed_response' => $book(['product_id' => 'ETH-USD']),
            'rate_limited' => Http::response(['message' => 'secret provider detail'], 429),
            'http_failure' => Http::response(['message' => 'secret provider detail'], 503),
        ];
        $sequence = Http::sequence();
        foreach ($cases as $response) {
            $sequence->pushResponse($response);
        }
        Http::fake(['*' => $sequence]);
        foreach (array_keys($cases) as $reason) {
            $exception = $this->assertReason($reason, fn () => (new CoinbasePublicMarketData)->bestBidAsk('BTC-USD', CarbonImmutable::now()));
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function test_only_closed_candles_are_returned_in_ascending_order(): void
    {
        CoinbasePublicFake::install();
        $now = CarbonImmutable::now();
        $candles = (new CoinbasePublicMarketData)->closedCandles('BTC-USD', 'FIVE_MINUTE', $now->subMinutes(30), $now);

        // 23:14:29 floors to 23:10; the 23:40 candle is still open at 23:44:29.
        $this->assertSame('2026-10-22 23:10:00', $candles[0]['starts_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-22 23:40:00', $candles[array_key_last($candles)]['ends_at']->format('Y-m-d H:i:s'));
        $this->assertCount(6, $candles);
        $this->assertSame(['100', '101.5', '99.5', '100.5', '12.25'], [$candles[0]['open'], $candles[0]['high'], $candles[0]['low'], $candles[0]['close'], $candles[0]['volume']]);
    }

    public function test_long_daily_ranges_are_chunked_below_the_venue_limit(): void
    {
        $fake = CoinbasePublicFake::install();
        $now = CarbonImmutable::now();
        $candles = (new CoinbasePublicMarketData)->closedCandles('BTC-USD', 'ONE_DAY', $now->startOfDay()->subDays(400), $now);

        $this->assertSame(2, $fake->count('/candles'));
        $this->assertCount(400, $candles, 'Every closed UTC day once; today is still open');
        $this->assertSame('2026-10-22 00:00:00', $candles[399]['ends_at']->format('Y-m-d H:i:s'));
    }

    public function test_misaligned_or_inconsistent_candles_reject_the_read(): void
    {
        $sequence = Http::sequence();
        Http::fake(['*' => $sequence]);
        foreach ([
            ['start' => '1792712401', 'low' => '1', 'high' => '2', 'open' => '1', 'close' => '2', 'volume' => '1'],
            ['start' => '1792712400', 'low' => '1.5', 'high' => '2', 'open' => '1', 'close' => '2', 'volume' => '1'],
            ['start' => '1792712400', 'low' => '1', 'high' => '2', 'open' => '1', 'close' => '2', 'volume' => '1e3'],
        ] as $candle) {
            $sequence->push(['candles' => [$candle]]);
            $now = CarbonImmutable::now();
            $this->assertReason('invalid_or_unreviewed_response', fn () => (new CoinbasePublicMarketData)->closedCandles('BTC-USD', 'FIVE_MINUTE', $now->subHour(), $now));
        }
    }

    private function assertReason(string $reason, callable $call): CryptoMarketDataException
    {
        try {
            $call();
        } catch (CryptoMarketDataException $exception) {
            $this->assertSame($reason, $exception->reason);

            return $exception;
        }
        $this->fail("Expected {$reason}");
    }
}
