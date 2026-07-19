<?php

namespace Tests\Feature\Trading;

use App\Jobs\CreateTradeDecisionJob;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\StrategyRun;
use App\Models\StrategyVersion;
use App\Models\UniverseVersion;
use App\Services\PaperTrading\PaperSessionService;
use Illuminate\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateTradeDecisionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_creates_trade_decision_with_context_and_policy_checks(): void
    {
        config()->set('broker.mode', 'paper');
        config()->set('trading.allowed_assets', ['BTC']);
        config()->set('trading.enabled', true);

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
        StrategyVersion::query()->create([
            'name' => 'decision-test', 'version' => '1.0.0', 'schema_version' => '1.0',
            'engine_version' => '0.1.0', 'status' => 'active', 'content_hash' => hash('sha256', 'decision-strategy'),
            'definition_json' => ['family' => 'trend'], 'activated_at' => now(),
        ]);
        UniverseVersion::query()->create([
            'name' => 'decision-test', 'version' => '1.0.0', 'status' => 'active',
            'content_hash' => hash('sha256', 'decision-universe'), 'symbols_json' => ['BTC'], 'activated_at' => now(),
        ]);
        app(PaperSessionService::class)->start($account, 'virtual', 10_000);

        $strategyRun = StrategyRun::query()->create([
            'strategy_name' => 'BTC_ETH_Momentum_Filtered_v1',
            'mode' => 'paper',
            'started_at' => now(),
            'status' => 'running',
        ]);

        $decisionId = app(Dispatcher::class)->dispatchSync(
            new CreateTradeDecisionJob($strategyRun->id, $asset->id, $account->id, [
                'action' => 'ENTER',
                'side' => 'buy',
                'score' => 0.8,
                'confidence' => 0.7,
                'market_context' => ['reference_price' => 100, 'regime' => ['state' => 'risk_on']],
                'signal_context' => ['ta' => ['atr_pct' => 0.03]],
            ])
        );
        $decisionId = $decisionId ?: (int) $strategyRun->tradeDecisions()->value('id');

        $this->assertDatabaseHas('trade_decisions', [
            'id' => $decisionId,
            'asset_id' => $asset->id,
        ]);
        $this->assertDatabaseHas('policy_checks', [
            'trade_decision_id' => $decisionId,
            'policy_name' => 'allowed_asset',
            'result' => 1,
        ]);
    }
}
