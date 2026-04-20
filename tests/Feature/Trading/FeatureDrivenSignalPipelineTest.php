<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\MarketCandle;
use App\Models\MarketQuote;
use App\Services\Strategy\FeatureEngine;
use App\Services\Strategy\SignalAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureDrivenSignalPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_engine_and_signal_pipeline_use_market_data_snapshots(): void
    {
        $btc = Asset::query()->create([
            'broker' => 'robinhood',
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        $eth = Asset::query()->create([
            'broker' => 'robinhood',
            'symbol' => 'ETH',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        $start = now()->subDays(230)->startOfDay();
        for ($i = 0; $i < 220; $i++) {
            $btcClose = 30000 + ($i * 35);
            $ethClose = 2000 + ($i * 4.25);

            MarketCandle::query()->create([
                'asset_id' => $btc->id,
                'symbol' => 'BTC',
                'timeframe' => '1d',
                'candle_open_time' => $start->copy()->addDays($i),
                'open' => $btcClose * 0.99,
                'high' => $btcClose * 1.01,
                'low' => $btcClose * 0.98,
                'close' => $btcClose,
                'volume' => 1000 + ($i * 3),
                'turnover_usd' => ($btcClose * 1000),
                'source' => 'test',
            ]);

            MarketCandle::query()->create([
                'asset_id' => $eth->id,
                'symbol' => 'ETH',
                'timeframe' => '1d',
                'candle_open_time' => $start->copy()->addDays($i),
                'open' => $ethClose * 0.99,
                'high' => $ethClose * 1.01,
                'low' => $ethClose * 0.98,
                'close' => $ethClose,
                'volume' => 3000 + ($i * 7),
                'turnover_usd' => ($ethClose * 3000),
                'source' => 'test',
            ]);
        }

        MarketQuote::query()->create([
            'asset_id' => $eth->id,
            'snapshot_time' => now(),
            'bid_price' => 2925,
            'ask_price' => 2930,
            'mid_price' => 2927.5,
            'last_price' => 2928,
            'spread_bps' => 17.1,
            'liquidity_score' => 0.9,
            'slippage_bps_estimate' => 12.5,
            'source' => 'test',
        ]);

        $featureSnapshot = app(FeatureEngine::class)->latestOrCompute($eth);

        $this->assertNotNull($featureSnapshot);
        $this->assertGreaterThan(0.50, (float) $featureSnapshot?->trend_score);
        $this->assertNotNull($featureSnapshot?->features_json['rsi_14'] ?? null);

        $signal = app(SignalAggregator::class)->evaluate($eth);

        $this->assertSame('strategy-v1', $signal['market_context']['market_rank']['skill']);
        $this->assertTrue((bool) $signal['signal_context']['ta']['feature_ready']);
        $this->assertArrayHasKey('regime', $signal['market_context']);
    }
}
