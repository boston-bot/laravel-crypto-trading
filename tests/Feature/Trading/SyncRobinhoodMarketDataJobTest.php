<?php

namespace Tests\Feature\Trading;

use App\Jobs\SyncRobinhoodMarketDataJob;
use App\Models\Asset;
use App\Models\BrokerCredential;
use App\Services\Broker\BrokerException;
use App\Services\Broker\Robinhood\RobinhoodClient;
use App\Services\MarketData\CoinbaseMarketDataClient;
use Illuminate\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncRobinhoodMarketDataJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_syncs_quotes_and_candles_for_allowed_assets(): void
    {
        config()->set('trading.allowed_assets', ['BTC']);

        $credential = BrokerCredential::query()->create([
            'broker' => 'robinhood',
            'label' => 'primary',
            'api_key_ref' => 'env:ROBINHOOD_PRIVATE_API_KEY',
            'secret_ref' => 'env:ROBINHOOD_X_API_KEY',
            'status' => 'active',
        ]);

        $asset = Asset::query()->create([
            'broker' => 'robinhood',
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        $client = Mockery::mock(RobinhoodClient::class);
        $client->shouldReceive('getBestBidAsk')
            ->once()
            ->andReturn([
                [
                    'symbol' => 'BTC-USD',
                    'bid_price' => '62000.10',
                    'ask_price' => '62010.25',
                    'last_trade_price' => '62005.10',
                    'updated_at' => now()->toIso8601String(),
                ],
            ]);

        $client->shouldReceive('getCandles')
            ->once()
            ->andReturn([
                [
                    'begins_at' => now()->subDays(1)->toIso8601String(),
                    'open_price' => '60000',
                    'high_price' => '62500',
                    'low_price' => '59800',
                    'close_price' => '62000',
                    'volume' => '120.50',
                ],
                [
                    'begins_at' => now()->toIso8601String(),
                    'open_price' => '62000',
                    'high_price' => '63000',
                    'low_price' => '61500',
                    'close_price' => '62800',
                    'volume' => '150.75',
                ],
            ]);

        $coinbaseClient = Mockery::mock(CoinbaseMarketDataClient::class);
        $coinbaseClient->shouldNotReceive('getCandles');

        $this->app->instance(RobinhoodClient::class, $client);
        $this->app->instance(CoinbaseMarketDataClient::class, $coinbaseClient);

        app(Dispatcher::class)->dispatchSync(new SyncRobinhoodMarketDataJob($credential->id, '1d'));

        $this->assertDatabaseHas('market_quotes', [
            'asset_id' => $asset->id,
            'source' => 'robinhood',
        ]);

        $this->assertDatabaseCount('market_candles', 2);
        $this->assertDatabaseHas('market_candles', [
            'asset_id' => $asset->id,
            'symbol' => 'BTC',
            'timeframe' => '1d',
            'source' => 'robinhood',
        ]);
    }

    public function test_job_continues_when_candle_endpoint_is_not_found(): void
    {
        config()->set('trading.allowed_assets', ['BTC']);

        $credential = BrokerCredential::query()->create([
            'broker' => 'robinhood',
            'label' => 'primary',
            'api_key_ref' => 'env:ROBINHOOD_PRIVATE_API_KEY',
            'secret_ref' => 'env:ROBINHOOD_X_API_KEY',
            'status' => 'active',
        ]);

        $asset = Asset::query()->create([
            'broker' => 'robinhood',
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        $client = Mockery::mock(RobinhoodClient::class);
        $client->shouldReceive('getBestBidAsk')
            ->once()
            ->andReturn([
                [
                    'symbol' => 'BTC-USD',
                    'bid_price' => '62000.10',
                    'ask_price' => '62010.25',
                    'last_trade_price' => '62005.10',
                    'updated_at' => now()->toIso8601String(),
                ],
            ]);

        $client->shouldReceive('getCandles')
            ->once()
            ->andThrow(new BrokerException('Robinhood API request failed (404):'));

        $coinbaseClient = Mockery::mock(CoinbaseMarketDataClient::class);
        $coinbaseClient->shouldReceive('getCandles')
            ->once()
            ->andReturn([
                [
                    'open_time' => now()->subDay()->toIso8601String(),
                    'close_time' => now()->toIso8601String(),
                    'open' => 60000,
                    'high' => 62000,
                    'low' => 59000,
                    'close' => 61000,
                    'volume' => 100,
                ],
            ]);

        $this->app->instance(RobinhoodClient::class, $client);
        $this->app->instance(CoinbaseMarketDataClient::class, $coinbaseClient);

        app(Dispatcher::class)->dispatchSync(new SyncRobinhoodMarketDataJob($credential->id, '1d'));

        $this->assertDatabaseHas('market_quotes', [
            'asset_id' => $asset->id,
            'source' => 'robinhood',
        ]);
        $this->assertDatabaseCount('market_candles', 1);
        $this->assertDatabaseHas('market_candles', [
            'asset_id' => $asset->id,
            'source' => 'coinbase',
        ]);
    }
}
