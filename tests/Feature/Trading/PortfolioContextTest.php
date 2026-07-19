<?php

namespace Tests\Feature\Trading;

use App\Jobs\EvaluateSignalsJob;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\EngineJob;
use App\Models\Position;
use App\Models\StrategyVersion;
use App\Models\UniverseVersion;
use App\Services\Execution\OrderSizingService;
use App\Services\PaperTrading\PaperSessionService;
use App\Services\Portfolio\PortfolioContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PortfolioContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_paper_context_uses_virtual_capital_and_ignores_live_positions(): void
    {
        [$strategy, $universe] = $this->activeVersions();
        $account = $this->account(0);
        $liveAsset = $this->asset('ETH');
        Position::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $liveAsset->id,
            'quantity' => 2,
            'avg_cost' => 2000,
            'market_value' => 4000,
            'unrealized_pnl' => 0,
            'snapshot_at' => now(),
        ]);
        $session = app(PaperSessionService::class)->start($account, 'virtual', 10_000);

        $context = app(PortfolioContextResolver::class)->resolve(
            'paper',
            $account,
            $strategy->id,
            $universe->id,
        );
        $size = app(OrderSizingService::class)->size($context, 50_000, [
            'confidence' => 0.8,
            'market_context' => ['regime' => ['state' => 'risk_on']],
        ]);

        $this->assertSame('paper', $context->mode());
        $this->assertSame('paper-session:'.$session->id, $context->contextId());
        $this->assertSame(10_000.0, $context->cash());
        $this->assertSame(10_000.0, $context->equity());
        $this->assertSame([], $context->positions());
        $this->assertGreaterThan(0, $size['notional']);
        $this->assertSame($context->contentHash(), $context->toPayload()['context_hash']);

        config()->set('research.engine.driver', 'database');
        config()->set('trading.universe.require_history', false);
        app()->call([new EvaluateSignalsJob($account->id, 'paper'), 'handle']);
        $payload = EngineJob::query()->sole()->payload_json;
        $this->assertSame($context->contentHash(), $payload['portfolio_context_hash']);
        $this->assertSame(10_000.0, (float) $payload['portfolio_context']['cash']);
        $this->assertSame([], $payload['portfolio_context']['positions']);
    }

    public function test_paper_evaluation_fails_closed_without_a_fully_pinned_active_session(): void
    {
        config()->set('research.engine.driver', 'database');
        config()->set('trading.universe.require_history', false);
        $account = $this->account(10_000);
        $this->asset('BTC');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fully pinned active paper session');

        app()->call([new EvaluateSignalsJob($account->id, 'paper'), 'handle']);
    }

    /** @return array{StrategyVersion, UniverseVersion} */
    private function activeVersions(): array
    {
        $strategy = StrategyVersion::query()->create([
            'name' => 'context-test',
            'version' => '1.0.0',
            'schema_version' => '1.0',
            'engine_version' => '0.1.0',
            'status' => 'active',
            'content_hash' => hash('sha256', 'context-strategy'),
            'definition_json' => ['family' => 'trend'],
            'activated_at' => now(),
        ]);
        $universe = UniverseVersion::query()->create([
            'name' => 'context-test',
            'version' => '1.0.0',
            'status' => 'active',
            'content_hash' => hash('sha256', 'context-universe'),
            'symbols_json' => ['BTC', 'ETH'],
            'activated_at' => now(),
        ]);

        return [$strategy, $universe];
    }

    private function account(float $equity): BrokerAccount
    {
        return BrokerAccount::query()->create([
            'broker' => 'coinbase',
            'external_account_id' => uniqid('portfolio-context-', true),
            'currency' => 'USD',
            'buying_power' => $equity,
            'cash_balance' => $equity,
            'equity' => $equity,
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
}
