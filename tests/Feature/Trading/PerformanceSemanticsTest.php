<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\MarketCandle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PerformanceSemanticsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_buy_and_hold_loss_is_never_returned_as_strategy_performance(): void
    {
        Carbon::setTestNow('2026-07-19 16:00:00 UTC');
        $this->account();
        $asset = $this->asset('BTC');
        $this->candle($asset, now()->subDays(30), 100);
        $this->candle($asset, now(), 90);

        $assets = $this->getJson('/api/ops/v1/assets?window_days=30')
            ->assertOk()
            ->assertJsonPath('data.performance.period_start', now()->subDays(30)->toIso8601String())
            ->assertJsonPath('data.performance.measurement_state', 'not_measured')
            ->assertJsonPath('data.assets.0.buy_and_hold_price_return_pct', -10)
            ->assertJsonPath('data.assets.0.costs_included', false)
            ->assertJsonPath('data.assets.0.pnl_contribution_pct', null)
            ->assertJsonPath('data.assets.0.held_period_linked_return_pct', null)
            ->json('data.assets.0');

        $this->assertArrayNotHasKey('portfolio_contribution_pct', $assets);
        $this->assertArrayNotHasKey('benchmark_return_pct', $assets);
        $this->assertArrayNotHasKey('strategy_return_pct', $assets);
    }

    public function test_overview_returns_null_instead_of_zero_without_measured_strategy_interval(): void
    {
        $this->account();

        $this->getJson('/api/ops/v1/overview?window_days=30')
            ->assertOk()
            ->assertJsonPath('data.paper.performance.measurement_state', 'not_measured')
            ->assertJsonPath('data.paper.portfolio_summary.strategy_return_pct', null)
            ->assertJsonPath('data.paper.portfolio_summary.relative_benchmark_return_pct', null)
            ->assertJsonPath('data.paper.portfolio_summary.formula', 'strategy_return_pct - benchmark_return_pct');
    }

    private function account(): BrokerAccount
    {
        return BrokerAccount::query()->create([
            'broker' => 'coinbase',
            'external_account_id' => 'performance-semantics',
            'currency' => 'USD',
            'buying_power' => 0,
            'cash_balance' => 0,
            'equity' => 0,
            'status' => 'active',
            'snapshot_at' => now(),
        ]);
    }

    private function asset(string $symbol): Asset
    {
        return Asset::query()->create([
            'broker' => 'coinbase',
            'symbol' => $symbol,
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);
    }

    private function candle(Asset $asset, Carbon $closeTime, float $close): void
    {
        MarketCandle::query()->create([
            'asset_id' => $asset->id,
            'symbol' => $asset->symbol,
            'timeframe' => '1h',
            'candle_open_time' => $closeTime->copy()->subHour(),
            'candle_close_time' => $closeTime,
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'volume' => 1,
            'source' => 'coinbase',
            'ingested_at' => $closeTime,
            'first_seen_at' => $closeTime,
            'available_at' => $closeTime,
            'is_final' => true,
            'source_revision' => 1,
            'content_hash' => hash('sha256', $asset->symbol.$closeTime->toIso8601String()),
            'quality_state' => 'valid',
        ]);
    }
}
