<?php

namespace Tests\Feature\Trading;

use App\Jobs\SyncCoinbaseMarketDataJob;
use App\Models\Asset;
use App\Models\BrokerCredential;
use App\Services\Broker\Coinbase\CoinbaseClient;
use Illuminate\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncCoinbaseMarketDataJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_syncs_coinbase_quotes_and_candles_for_allowed_assets(): void
    {
        config()->set('trading.allowed_assets', ['BTC']);

        $credential = BrokerCredential::query()->create([
            'broker' => 'coinbase',
            'label' => 'primary',
            'api_key_ref' => 'env:COINBASE_API_PRIVATE_KEY',
            'secret_ref' => 'env:COINBASE_API_KEY',
            'status' => 'active',
        ]);

        $asset = Asset::query()->create([
            'broker' => 'coinbase',
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        $client = Mockery::mock(CoinbaseClient::class);
        $client->shouldReceive('getBestBidAsk')
            ->once()
            ->andReturn([
                [
                    'product_id' => 'BTC-USD',
                    'bids' => [['price' => '62000.10']],
                    'asks' => [['price' => '62010.25']],
                    'price' => '62005.10',
                ],
            ]);

        $client->shouldReceive('getCandles')
            ->once()
            ->andReturn([
                [
                    'open_time' => now()->subDays(1)->toIso8601String(),
                    'close_time' => now()->toIso8601String(),
                    'open' => 60000,
                    'high' => 62500,
                    'low' => 59800,
                    'close' => 62000,
                    'volume' => 120.5,
                    'turnover_usd' => 7450000,
                ],
            ]);

        $this->app->instance(CoinbaseClient::class, $client);

        app(Dispatcher::class)->dispatchSync(new SyncCoinbaseMarketDataJob($credential->id, '1d'));

        $this->assertDatabaseHas('market_quotes', [
            'asset_id' => $asset->id,
            'source' => 'coinbase',
        ]);

        $this->assertDatabaseCount('market_candles', 1);
        $this->assertDatabaseHas('market_candles', [
            'asset_id' => $asset->id,
            'symbol' => 'BTC',
            'timeframe' => '1d',
            'source' => 'coinbase',
        ]);
    }
}
