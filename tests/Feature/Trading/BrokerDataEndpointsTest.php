<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\BrokerOrder;
use App\Models\DailyPortfolioSnapshot;
use App\Models\MarketCandle;
use App\Models\MarketQuote;
use App\Models\PaperPortfolioSnapshot;
use App\Models\PaperPosition;
use App\Models\Position;
use App\Models\RiskEvent;
use App\Models\TradeAttribution;
use App\Models\TradeDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrokerDataEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_broker_data_endpoints_return_account_and_related_details(): void
    {
        config()->set('broker.default', 'robinhood');

        $account = BrokerAccount::query()->create([
            'broker' => 'robinhood',
            'external_account_id' => 'acct-1',
            'account_type' => 'cash',
            'currency' => 'USD',
            'buying_power' => 120,
            'cash_balance' => 100,
            'equity' => 125,
            'status' => 'active',
            'snapshot_at' => now(),
        ]);

        $asset = Asset::query()->create([
            'broker' => 'robinhood',
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
            'min_order_notional' => 1,
        ]);

        Position::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'quantity' => 0.1,
            'avg_cost' => 100,
            'market_value' => 12.5,
            'unrealized_pnl' => 2.5,
            'snapshot_at' => now(),
        ]);

        $decision = TradeDecision::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'decision' => 'buy',
            'side' => 'buy',
            'score' => 0.72,
            'confidence' => 0.82,
            'requested_quantity' => 0.1,
            'requested_notional' => 10.0,
            'requires_human_approval' => false,
            'status' => 'approved',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $order = BrokerOrder::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'trade_decision_id' => $decision->id,
            'external_order_id' => 'order-123',
            'client_order_id' => 'client-123',
            'side' => 'buy',
            'order_type' => 'market',
            'requested_quantity' => 0.1,
            'requested_notional' => 10.0,
            'status' => 'filled',
            'filled_quantity' => 0.1,
            'filled_notional' => 10.0,
            'avg_fill_price' => 100.0,
            'submitted_at' => now(),
            'filled_at' => now(),
        ]);

        RiskEvent::query()->create([
            'severity' => 'warning',
            'event_type' => 'max_drawdown_warning',
            'asset_id' => $asset->id,
            'broker_order_id' => $order->id,
            'trade_decision_id' => $decision->id,
            'message' => 'Drawdown threshold approaching.',
            'triggered_at' => now(),
        ]);

        DailyPortfolioSnapshot::query()->create([
            'broker_account_id' => $account->id,
            'equity' => 125,
            'cash' => 100,
            'invested_value' => 25,
            'realized_pnl' => 1.25,
            'unrealized_pnl' => 2.5,
            'drawdown_pct' => 1.2,
            'snapshot_date' => today(),
        ]);

        PaperPosition::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'quantity' => 0.1,
            'avg_entry_price' => 100.0,
            'cost_basis' => 10.0,
            'market_price' => 120.0,
            'market_value' => 12.0,
            'unrealized_pnl' => 2.0,
            'realized_pnl' => 1.0,
            'opened_at' => now()->subDay(),
            'updated_snapshot_at' => now(),
        ]);

        PaperPortfolioSnapshot::query()->create([
            'broker_account_id' => $account->id,
            'snapshot_time' => now()->startOfMinute(),
            'equity' => 112.0,
            'cash' => 100.0,
            'invested_value' => 12.0,
            'realized_pnl' => 1.0,
            'unrealized_pnl' => 2.0,
            'gross_exposure_pct' => 10.71,
            'heat_score' => 10.71,
            'drawdown_pct' => 0.5,
        ]);

        TradeAttribution::query()->create([
            'trade_decision_id' => $decision->id,
            'broker_order_id' => $order->id,
            'asset_id' => $asset->id,
            'expected_probability' => 0.63,
            'expected_expectancy' => 0.02,
            'realized_return_pct' => 1.20,
            'realized_pnl' => 1.00,
            'attributed_at' => now(),
            'attribution_json' => ['mode' => 'paper'],
        ]);

        MarketQuote::query()->create([
            'asset_id' => $asset->id,
            'snapshot_time' => now(),
            'bid_price' => 99.8,
            'ask_price' => 100.2,
            'mid_price' => 100.0,
            'last_price' => 100.1,
            'spread_bps' => 40.0,
            'liquidity_score' => 0.8,
            'slippage_bps_estimate' => 20.0,
            'source' => 'test',
        ]);

        MarketCandle::query()->create([
            'asset_id' => $asset->id,
            'symbol' => 'BTC',
            'timeframe' => '1d',
            'candle_open_time' => now()->subDay(),
            'open' => 95.0,
            'high' => 101.0,
            'low' => 94.0,
            'close' => 100.0,
            'volume' => 1000.0,
            'turnover_usd' => 100000.0,
            'source' => 'test',
        ]);

        $this->getJson('/api/broker/overview')
            ->assertOk()
            ->assertJsonPath('data.account.id', $account->id)
            ->assertJsonPath('data.metrics.open_positions_count', 1);

        $this->getJson('/api/broker/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $account->id);

        $this->getJson("/api/broker/accounts/{$account->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $account->id);

        $this->getJson("/api/broker/accounts/{$account->id}/positions?open_only=1")
            ->assertOk()
            ->assertJsonPath('data.0.asset.symbol', 'BTC');

        $this->getJson("/api/broker/accounts/{$account->id}/paper-positions?open_only=1")
            ->assertOk()
            ->assertJsonPath('data.0.asset.symbol', 'BTC');

        $this->getJson("/api/broker/accounts/{$account->id}/paper-performance")
            ->assertOk()
            ->assertJsonPath('data.trade_count', 1)
            ->assertJsonPath('data.wins', 1);

        $this->getJson("/api/broker/accounts/{$account->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.0.external_order_id', 'order-123');

        $this->getJson('/api/broker/assets?tradable=1')
            ->assertOk()
            ->assertJsonPath('data.0.symbol', 'BTC');

        $this->getJson('/api/broker/market-data/quotes?symbol=BTC')
            ->assertOk()
            ->assertJsonPath('data.0.asset.symbol', 'BTC');

        $this->getJson('/api/broker/market-data/candles?symbol=BTC&timeframe=1d')
            ->assertOk()
            ->assertJsonPath('data.0.asset.symbol', 'BTC')
            ->assertJsonPath('data.0.timeframe', '1d');

        $this->getJson('/api/broker/trade-decisions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $decision->id);

        $this->getJson('/api/broker/risk-events')
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'max_drawdown_warning');
    }

    public function test_sync_endpoint_queues_robinhood_sync_jobs(): void
    {
        Queue::fake();
        config()->set('broker.default', 'robinhood');

        $this->postJson('/api/broker/sync')
            ->assertStatus(202)
            ->assertJsonPath('message', 'Robinhood sync jobs queued.');
    }
}
