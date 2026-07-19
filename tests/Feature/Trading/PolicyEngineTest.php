<?php

namespace Tests\Feature\Trading;

use App\Data\Trading\TradeCandidate;
use App\Enums\OrderSide;
use App\Models\Asset;
use App\Services\Risk\PolicyEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PolicyEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_blocks_asset_outside_allowlist(): void
    {
        config()->set('broker.mode', 'live');
        config()->set('trading.enabled', true);
        config()->set('trading.allowed_assets', ['BTC', 'ETH']);

        $asset = Asset::query()->create([
            'broker' => 'robinhood',
            'symbol' => 'DOGE',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        $candidate = new TradeCandidate(
            assetId: $asset->id,
            symbol: 'DOGE',
            side: OrderSide::BUY,
            quantity: 1.0,
            notionalUsd: 10.0,
            score: 0.7,
            confidence: 0.8,
        );

        $result = app(PolicyEngine::class)->evaluate($candidate, $asset);

        $this->assertFalse($result->passed);
        $this->assertArrayHasKey('allowed_asset', $result->messages);
    }

    public function test_policy_blocks_when_kill_switch_is_active(): void
    {
        config()->set('broker.mode', 'live');
        config()->set('trading.enabled', true);
        config()->set('trading.allowed_assets', ['BTC']);
        config()->set('trading.kill_switch_cache_key', 'trading:frozen');
        Cache::put('trading:frozen', true);

        $asset = Asset::query()->create([
            'broker' => 'robinhood',
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        $candidate = new TradeCandidate(
            assetId: $asset->id,
            symbol: 'BTC',
            side: OrderSide::BUY,
            quantity: 0.1,
            notionalUsd: 10.0,
            score: 0.75,
            confidence: 0.9,
        );

        $result = app(PolicyEngine::class)->evaluate($candidate, $asset);

        $this->assertFalse($result->passed);
        $this->assertArrayHasKey('kill_switch', $result->messages);
    }
}
