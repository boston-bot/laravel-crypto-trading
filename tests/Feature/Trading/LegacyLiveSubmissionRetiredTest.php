<?php

namespace Tests\Feature\Trading;

use App\Enums\TradeDecisionAction;
use App\Enums\TradingDecisionStatus;
use App\Jobs\SubmitBrokerOrderJob;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\BrokerCredential;
use App\Models\TradeDecision;
use App\Models\User;
use App\Services\Broker\Coinbase\CoinbaseClient;
use App\Services\Broker\Robinhood\RobinhoodClient;
use App\Services\Execution\TradeExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * CRYPTO-AUTOMATION-PLAN.md C0: no legacy path may reach a crypto venue's order endpoint,
 * even with every write flag enabled.
 */
class LegacyLiveSubmissionRetiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_mode_refuses_submission_for_both_venues_with_all_write_flags_on(): void
    {
        $this->enableEveryWriteFlag();
        $coinbase = Mockery::mock(CoinbaseClient::class);
        $coinbase->shouldNotReceive('placeOrder');
        $this->app->instance(CoinbaseClient::class, $coinbase);
        $robinhood = Mockery::mock(RobinhoodClient::class);
        $robinhood->shouldNotReceive('placeOrder');
        $this->app->instance(RobinhoodClient::class, $robinhood);

        foreach (['coinbase', 'robinhood'] as $broker) {
            $decision = $this->decision($broker, TradingDecisionStatus::APPROVED);
            try {
                app(TradeExecutionService::class)->submitDecision($decision->id);
                $this->fail("Legacy live submission accepted for {$broker}");
            } catch (\RuntimeException $exception) {
                $this->assertSame('Legacy live order submission is retired.', $exception->getMessage());
            }
            $this->assertSame(TradingDecisionStatus::APPROVED, $decision->fresh()->status);
        }

        $this->assertDatabaseCount('broker_orders', 0);
        Http::assertNothingSent();
    }

    public function test_any_non_paper_mode_is_refused(): void
    {
        config(['broker.mode' => 'shadow']);
        $decision = $this->decision('coinbase', TradingDecisionStatus::APPROVED);

        $this->expectExceptionMessage('Legacy live order submission is retired.');
        app(TradeExecutionService::class)->submitDecision($decision->id);
    }

    public function test_approval_api_refuses_outside_paper_mode_without_changing_or_queueing(): void
    {
        Bus::fake();
        $this->enableEveryWriteFlag();
        $user = User::factory()->create();
        config(['mcp.operator_emails' => [$user->email], 'mcp.approver_emails' => [$user->email]]);
        $decision = $this->decision('coinbase', TradingDecisionStatus::AWAITING_HUMAN_APPROVAL);

        $this->actingAs($user)->postJson("/api/trade-decisions/{$decision->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Legacy live order submission is retired; approval is available in paper mode only.');

        $this->assertSame(TradingDecisionStatus::AWAITING_HUMAN_APPROVAL, $decision->fresh()->status);
        $this->assertNull($decision->fresh()->approved_by);
        Bus::assertNotDispatched(SubmitBrokerOrderJob::class);
    }

    public function test_approval_command_refuses_outside_paper_mode_without_changing_or_queueing(): void
    {
        Bus::fake();
        $this->enableEveryWriteFlag();
        $decision = $this->decision('robinhood', TradingDecisionStatus::AWAITING_HUMAN_APPROVAL);

        $this->artisan('trading:approve-decision', ['decisionId' => $decision->id])
            ->expectsOutputToContain('approval is available in paper mode only')
            ->assertFailed();

        $this->assertSame(TradingDecisionStatus::AWAITING_HUMAN_APPROVAL, $decision->fresh()->status);
        Bus::assertNotDispatched(SubmitBrokerOrderJob::class);
    }

    public function test_agentic_write_flag_does_not_arm_crypto_venue_clients(): void
    {
        config(['mcp.brokerage_writes_enabled' => true]);
        foreach ([
            CoinbaseClient::class => 'Coinbase writes are disabled.',
            RobinhoodClient::class => 'Robinhood Crypto writes are disabled.',
        ] as $client => $message) {
            try {
                app($client)->placeOrder(new BrokerCredential, []);
                $this->fail("{$client} accepted a write with only the agentic flag enabled");
            } catch (\RuntimeException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_no_application_code_calls_a_crypto_venue_order_endpoint(): void
    {
        // A future execution boundary (plan C4/C5) must be added here deliberately, with its own tests.
        $callers = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            if (preg_match('/->placeOrder\s*\(/', $file->getContents()) === 1) {
                $callers[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $callers);
    }

    private function enableEveryWriteFlag(): void
    {
        config([
            'broker.mode' => 'live',
            'mcp.brokerage_writes_enabled' => true,
            'broker.coinbase.writes_enabled' => true,
            'broker.robinhood.writes_enabled' => true,
        ]);
    }

    private function decision(string $broker, TradingDecisionStatus $status): TradeDecision
    {
        BrokerCredential::query()->firstOrCreate(['broker' => $broker, 'label' => "{$broker}-test"], [
            'api_key_ref' => 'test-private-key',
            'secret_ref' => 'test-api-key',
            'status' => 'active',
        ]);
        $account = BrokerAccount::query()->create([
            'broker' => $broker,
            'external_account_id' => "{$broker}-acct-".uniqid(),
            'currency' => 'USD',
            'buying_power' => 100,
            'cash_balance' => 100,
            'equity' => 100,
            'status' => 'active',
            'snapshot_at' => now(),
        ]);
        $asset = Asset::query()->firstOrCreate(['broker' => $broker, 'symbol' => 'BTC'], [
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        return TradeDecision::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'decision' => TradeDecisionAction::BUY->value,
            'side' => 'buy',
            'score' => 0.9,
            'confidence' => 0.9,
            'requested_quantity' => '0.001',
            'requested_notional' => '10',
            'requires_human_approval' => $status === TradingDecisionStatus::AWAITING_HUMAN_APPROVAL,
            'status' => $status->value,
            'idempotency_key' => uniqid("{$broker}-", true),
        ]);
    }
}
