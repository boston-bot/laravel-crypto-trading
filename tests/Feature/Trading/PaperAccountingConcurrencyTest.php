<?php

namespace Tests\Feature\Trading;

use App\Enums\TradingDecisionStatus;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\MarketQuote;
use App\Models\PaperSession;
use App\Models\TradeDecision;
use App\Services\Execution\TradeExecutionService;
use App\Services\PaperTrading\PaperSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PaperAccountingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_competing_buy_intents_cannot_reserve_or_spend_the_same_cash(): void
    {
        config()->set('broker.mode', 'paper');
        $account = $this->account();
        $asset = $this->asset();
        $session = app(PaperSessionService::class)->start($account, 'virtual', 100);
        $this->quote($asset);
        $first = $this->decision($account, $asset, 'competing-buy-1', 60);
        $second = $this->decision($account, $asset, 'competing-buy-2', 60);

        app(TradeExecutionService::class)->submitDecision($first->id);

        try {
            app(TradeExecutionService::class)->submitDecision($second->id);
            $this->fail('The second buy should not be able to reserve already-spent paper cash.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('only $', $exception->getMessage());
        }

        $this->assertDatabaseCount('broker_orders', 1);
        $this->assertDatabaseCount('paper_order_reservations', 1);
        $this->assertDatabaseHas('paper_order_reservations', [
            'paper_session_id' => $session->id,
            'idempotency_key' => 'competing-buy-1',
            'status' => 'filled',
        ]);
        $this->assertSame(0.0, (float) PaperSession::query()->findOrFail($session->id)->reserved_cash);
    }

    private function decision(BrokerAccount $account, Asset $asset, string $key, float $notional): TradeDecision
    {
        return TradeDecision::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'decision' => 'buy',
            'side' => 'buy',
            'score' => 0.9,
            'confidence' => 0.9,
            'requested_quantity' => $notional / 100,
            'requested_notional' => $notional,
            'requires_human_approval' => false,
            'status' => TradingDecisionStatus::APPROVED->value,
            'idempotency_key' => $key,
            'market_context_json' => ['reference_price' => 100],
        ]);
    }

    private function account(): BrokerAccount
    {
        return BrokerAccount::query()->create([
            'broker' => 'coinbase',
            'external_account_id' => 'reservation-account',
            'currency' => 'USD',
            'buying_power' => 0,
            'cash_balance' => 0,
            'equity' => 0,
            'status' => 'active',
            'snapshot_at' => now(),
        ]);
    }

    private function asset(): Asset
    {
        return Asset::query()->create([
            'broker' => 'coinbase',
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);
    }

    private function quote(Asset $asset): void
    {
        MarketQuote::query()->create([
            'asset_id' => $asset->id,
            'snapshot_time' => now(),
            'bid_price' => 99.5,
            'ask_price' => 100.5,
            'mid_price' => 100,
            'last_price' => 100,
            'spread_bps' => 100,
            'liquidity_score' => 0.7,
            'slippage_bps_estimate' => 35,
            'source' => 'test',
        ]);
    }
}
