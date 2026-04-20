<?php

namespace Tests\Feature\Trading;

use App\Enums\TradeDecisionAction;
use App\Enums\TradingDecisionStatus;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\BrokerCredential;
use App\Models\TradeDecision;
use App\Services\Broker\Coinbase\CoinbaseClient;
use App\Services\Execution\TradeExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CoinbaseTradeExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_mode_submits_and_persists_coinbase_order(): void
    {
        config()->set('broker.mode', 'live');

        BrokerCredential::query()->create([
            'broker' => 'coinbase',
            'label' => 'coinbase-primary',
            'api_key_ref' => 'env:COINBASE_API_PRIVATE_KEY',
            'secret_ref' => 'env:COINBASE_API_KEY',
            'status' => 'active',
        ]);

        $account = BrokerAccount::query()->create([
            'broker' => 'coinbase',
            'external_account_id' => 'cb-acct-1',
            'currency' => 'USD',
            'buying_power' => 100,
            'cash_balance' => 100,
            'equity' => 100,
            'status' => 'active',
            'snapshot_at' => now(),
        ]);

        $asset = Asset::query()->create([
            'broker' => 'coinbase',
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        $decision = TradeDecision::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'decision' => TradeDecisionAction::BUY->value,
            'side' => 'buy',
            'score' => 0.9,
            'confidence' => 0.9,
            'requested_quantity' => 0.001,
            'requested_notional' => 10,
            'requires_human_approval' => false,
            'status' => TradingDecisionStatus::APPROVED->value,
            'idempotency_key' => 'coinbase-live-test',
        ]);

        $client = Mockery::mock(CoinbaseClient::class);
        $client->shouldReceive('placeOrder')
            ->once()
            ->andReturn([
                'body' => [
                    'success' => true,
                    'success_response' => [
                        'order_id' => 'order-coinbase-1',
                    ],
                ],
            ]);

        $client->shouldReceive('getOrder')
            ->once()
            ->with(Mockery::any(), 'order-coinbase-1')
            ->andReturn([
                'body' => [
                    'order' => [
                        'order_id' => 'order-coinbase-1',
                        'client_order_id' => 'coinbase-live-test',
                        'product_id' => 'BTC-USD',
                        'side' => 'BUY',
                        'status' => 'OPEN',
                        'time_in_force' => 'GOOD_UNTIL_CANCELLED',
                        'order_configuration' => [
                            'market_market_ioc' => [
                                'quote_size' => '10',
                                'base_size' => '0.001',
                            ],
                        ],
                        'created_time' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $this->app->instance(CoinbaseClient::class, $client);

        app(TradeExecutionService::class)->submitDecision($decision->id);

        $this->assertDatabaseHas('broker_orders', [
            'trade_decision_id' => $decision->id,
            'external_order_id' => 'order-coinbase-1',
            'client_order_id' => 'coinbase-live-test',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('trade_decisions', [
            'id' => $decision->id,
            'status' => 'submitted',
        ]);
    }
}
