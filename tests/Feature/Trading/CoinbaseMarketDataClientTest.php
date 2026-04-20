<?php

namespace Tests\Feature\Trading;

use App\Services\MarketData\CoinbaseMarketDataClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoinbaseMarketDataClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_maps_coinbase_candles_to_normalized_shape(): void
    {
        Http::fake([
            'https://api.exchange.coinbase.com/products/BTC-USD/candles*' => Http::response([
                [1713000000, 60000.0, 62000.0, 60500.0, 61500.0, 120.5],
                [1713086400, 61000.0, 63000.0, 61500.0, 62500.0, 140.75],
            ], 200),
        ]);

        $candles = app(CoinbaseMarketDataClient::class)->getCandles('BTC', '1d', 100);

        $this->assertCount(2, $candles);
        $this->assertSame(60500.0, $candles[0]['open']);
        $this->assertSame(62000.0, $candles[0]['high']);
        $this->assertSame(60000.0, $candles[0]['low']);
        $this->assertSame(61500.0, $candles[0]['close']);
        $this->assertSame(120.5, $candles[0]['volume']);
    }

    public function test_client_aggregates_one_hour_candles_into_four_hour_bars(): void
    {
        // 4 sequential 1h candles in same 4h window.
        $base = 1712966400; // UTC midnight boundary
        Http::fake([
            'https://api.exchange.coinbase.com/products/BTC-USD/candles*' => Http::response([
                [$base, 60000.0, 60500.0, 60200.0, 60300.0, 10.0],
                [$base + 3600, 60200.0, 61000.0, 60300.0, 60900.0, 11.0],
                [$base + 7200, 60800.0, 61200.0, 60900.0, 61000.0, 12.0],
                [$base + 10800, 60700.0, 61300.0, 61000.0, 61100.0, 13.0],
            ], 200),
        ]);

        $candles = app(CoinbaseMarketDataClient::class)->getCandles('BTC', '4h', 50);

        $this->assertCount(1, $candles);
        $this->assertSame(60200.0, $candles[0]['open']);
        $this->assertSame(61300.0, $candles[0]['high']);
        $this->assertSame(60000.0, $candles[0]['low']);
        $this->assertSame(61100.0, $candles[0]['close']);
        $this->assertSame(46.0, $candles[0]['volume']);
    }
}
