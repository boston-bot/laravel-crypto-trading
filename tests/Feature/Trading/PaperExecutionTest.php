<?php

namespace Tests\Feature\Trading;

use App\Enums\TradeDecisionAction;
use App\Enums\TradingDecisionStatus;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\BrokerOrder;
use App\Models\MarketQuote;
use App\Models\PaperOrderEvent;
use App\Models\PaperPortfolioSnapshot;
use App\Models\PaperPosition;
use App\Models\TradeAttribution;
use App\Models\TradeDecision;
use App\Services\Broker\Robinhood\RobinhoodClient;
use App\Services\Execution\TradeExecutionService;
use App\Services\PaperTrading\PaperSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaperExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_paper_mode_submits_simulated_order_without_live_broker_call(): void
    {
        config()->set('broker.mode', 'paper');

        $client = Mockery::mock(RobinhoodClient::class);
        $client->shouldNotReceive('placeOrder');
        $this->app->instance(RobinhoodClient::class, $client);

        $account = BrokerAccount::query()->create([
            'broker' => 'robinhood',
            'external_account_id' => 'acct-1',
            'currency' => 'USD',
            'buying_power' => 100,
            'cash_balance' => 100,
            'equity' => 100,
            'status' => 'active',
            'snapshot_at' => now(),
        ]);

        $asset = Asset::query()->create([
            'broker' => 'robinhood',
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        app(PaperSessionService::class)->start($account, 'virtual', 100);

        $decision = TradeDecision::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'decision' => TradeDecisionAction::BUY->value,
            'side' => 'buy',
            'score' => 0.9,
            'confidence' => 0.9,
            'requested_quantity' => 0.1,
            'requested_notional' => 10,
            'requires_human_approval' => false,
            'status' => TradingDecisionStatus::APPROVED->value,
            'idempotency_key' => 'paper-test-idempotency-key',
            'market_context_json' => [
                'market_rank' => [
                    'factor_breakdown' => ['relative_strength' => 0.7],
                ],
            ],
        ]);

        MarketQuote::query()->create([
            'asset_id' => $asset->id,
            'snapshot_time' => now(),
            'bid_price' => 99.5,
            'ask_price' => 100.5,
            'mid_price' => 100.0,
            'last_price' => 100.1,
            'spread_bps' => 100.0,
            'liquidity_score' => 0.7,
            'slippage_bps_estimate' => 35.0,
            'source' => 'test',
        ]);

        app(TradeExecutionService::class)->submitDecision($decision->id);

        $this->assertDatabaseCount('broker_orders', 1);
        $this->assertDatabaseHas('broker_orders', [
            'trade_decision_id' => $decision->id,
            'status' => 'filled',
        ]);
        $this->assertDatabaseHas('trade_decisions', [
            'id' => $decision->id,
            'status' => 'filled',
        ]);
        $this->assertDatabaseCount('paper_order_events', 1);
        $this->assertDatabaseHas('paper_positions', [
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
        ]);
        $this->assertDatabaseCount('paper_portfolio_snapshots', 1);
        $this->assertDatabaseCount('trade_attributions', 1);
        $this->assertDatabaseCount('paper_ledger_entries', 3);
        $this->assertTrue(
            BrokerOrder::query()->first()?->external_order_id !== null
        );
        $this->assertGreaterThan(0, PaperOrderEvent::query()->count());
        $this->assertNotNull(PaperPosition::query()->first()?->updated_snapshot_at);
        $this->assertNotNull(PaperPortfolioSnapshot::query()->first()?->snapshot_time);
        $this->assertNotNull(TradeAttribution::query()->first()?->expected_probability);
    }
}
