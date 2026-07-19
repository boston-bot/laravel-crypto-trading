<?php

namespace Tests\Feature\Trading;

use App\Enums\TradingDecisionStatus;
use App\Jobs\EvaluateSignalsJob;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\EngineJob;
use App\Models\FeeScheduleSnapshot;
use App\Models\MarketCandle;
use App\Models\MarketCandleRevision;
use App\Models\OrderBookSummary;
use App\Models\StrategyVersion;
use App\Models\TradeDecision;
use App\Models\UniverseVersion;
use App\Services\Execution\TradeExecutionService;
use App\Services\MarketData\CandleIngestionService;
use App\Services\MarketData\SpreadAnalysisService;
use App\Services\PaperTrading\PaperSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class ResearchPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_database_engine_evaluation_is_idempotent_per_closed_four_hour_bar(): void
    {
        Carbon::setTestNow('2026-07-16 12:05:00 UTC');
        config()->set('research.engine.driver', 'database');
        config()->set('trading.universe.require_history', false);
        $account = $this->account();
        $this->activatePinnedVersions();
        app(PaperSessionService::class)->start($account, 'virtual', 10_000);
        foreach (['BTC', 'ETH', 'SOL'] as $symbol) {
            $this->asset($symbol);
        }

        app()->call([new EvaluateSignalsJob($account->id, 'paper'), 'handle']);
        app()->call([new EvaluateSignalsJob($account->id, 'paper'), 'handle']);

        $this->assertDatabaseCount('engine_jobs', 1);
        $job = EngineJob::query()->firstOrFail();
        $this->assertSame('pending', $job->status);
        $this->assertSame('2026-07-16T12:00:00.000000Z', $job->as_of->format('Y-m-d\TH:i:s.u\Z'));
        $this->assertDatabaseHas('strategy_runs', ['status' => 'waiting_engine']);
        $this->assertDatabaseCount('strategy_runs', 1);
    }

    public function test_candle_changes_are_archived_as_revisions(): void
    {
        $asset = $this->asset('BTC');
        $service = app(CandleIngestionService::class);
        $base = [
            'open_time' => '2026-07-16T10:00:00Z', 'close_time' => '2026-07-16T11:00:00Z',
            'open' => 100, 'high' => 102, 'low' => 99, 'close' => 101, 'volume' => 25, 'is_final' => true,
        ];
        $service->ingest($asset, '1h', [$base]);
        $service->ingest($asset, '1h', [[...$base, 'high' => 103, 'close' => 102]]);

        $this->assertDatabaseCount('market_candles', 1);
        $this->assertDatabaseCount('market_candle_revisions', 1);
        $this->assertSame(2, MarketCandle::query()->firstOrFail()->source_revision);
        $this->assertSame('101.00000000', (string) MarketCandleRevision::query()->firstOrFail()->values_json['close']);
    }

    public function test_paper_execution_rejects_missing_observed_price_without_fallback(): void
    {
        config()->set('broker.mode', 'paper');
        $account = $this->account();
        $asset = $this->asset('BTC');
        $decision = TradeDecision::query()->create([
            'broker_account_id' => $account->id, 'asset_id' => $asset->id,
            'decision' => 'buy', 'side' => 'buy', 'score' => 0.8, 'confidence' => 0.7,
            'requested_quantity' => 0.1, 'requested_notional' => 10,
            'requires_human_approval' => false, 'status' => 'approved', 'idempotency_key' => 'no-fallback',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('synthetic fallback prices are prohibited');
        app(TradeExecutionService::class)->submitDecision($decision->id);
    }

    public function test_expired_human_approval_is_failed_closed(): void
    {
        config()->set('broker.mode', 'paper');
        $account = $this->account();
        $asset = $this->asset('BTC');
        $decision = TradeDecision::query()->create([
            'broker_account_id' => $account->id, 'asset_id' => $asset->id,
            'decision' => 'buy', 'side' => 'buy', 'score' => 0.8, 'confidence' => 0.7,
            'requested_quantity' => 0.1, 'requested_notional' => 10,
            'requires_human_approval' => true, 'status' => TradingDecisionStatus::AWAITING_HUMAN_APPROVAL->value,
            'signal_expires_at' => now()->subMinute(), 'idempotency_key' => 'expired-approval',
        ]);

        $this->postJson('/api/trade-decisions/'.$decision->id.'/approve', ['actor' => 'test'])
            ->assertStatus(422)->assertJsonPath('message', 'Trade decision failed approval revalidation.');
        $this->assertDatabaseHas('trade_decisions', ['id' => $decision->id, 'status' => 'blocked_by_policy']);
    }

    public function test_shadow_spread_records_after_cost_classification_and_read_endpoints(): void
    {
        $now = now()->startOfSecond();
        foreach (['coinbase', 'kraken'] as $venue) {
            FeeScheduleSnapshot::query()->create([
                'venue' => $venue, 'account_scope' => 'test', 'maker_fee_bps' => 10, 'taker_fee_bps' => 10,
                'effective_at' => $now, 'expires_at' => $now->copy()->addHour(), 'content_hash' => hash('sha256', $venue),
            ]);
        }
        OrderBookSummary::query()->create([
            'venue' => 'coinbase', 'product_id' => 'BTC-USD', 'bucket_time' => $now,
            'event_time' => $now, 'received_at' => $now, 'best_bid' => 99, 'best_ask' => 100,
            'spread_bps' => 100, 'book_age_ms' => 10, 'is_valid' => true,
            'depth_json' => ['500' => ['buy_vwap' => 100, 'sell_vwap' => 99]],
        ]);
        OrderBookSummary::query()->create([
            'venue' => 'kraken', 'product_id' => 'BTC-USD', 'bucket_time' => $now,
            'event_time' => $now, 'received_at' => $now->copy()->addMilliseconds(20), 'best_bid' => 102, 'best_ask' => 103,
            'spread_bps' => 98, 'book_age_ms' => 10, 'is_valid' => true,
            'depth_json' => ['500' => ['buy_vwap' => 103, 'sell_vwap' => 102]],
        ]);

        $observation = app(SpreadAnalysisService::class)->analyze('BTC-USD', 500, 'coinbase', 'kraken');
        $this->assertSame('executable', $observation->classification);
        $this->assertGreaterThan(0, (float) $observation->net_edge_bps);
        $this->assertDatabaseCount('spread_observations', 1);
        $this->getJson('/api/research/spreads')->assertOk()->assertJsonPath('shadow_only', true)->assertJsonPath('execution_enabled', false);
        $this->getJson('/api/research/data-health')->assertOk()->assertJsonPath('spread_shadow.execution_enabled', false);
    }

    public function test_shared_evaluation_contract_fixture_has_required_shape(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('contracts/fixtures/evaluation-result-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['engine_version', 'schema_version', 'job_id', 'as_of', 'valid_until', 'manifest_hash', 'proposals'] as $field) {
            $this->assertArrayHasKey($field, $fixture);
        }
        $this->assertContains($fixture['proposals'][0]['action'], ['ENTER', 'EXIT', 'HOLD']);
        $this->assertGreaterThanOrEqual(0, $fixture['proposals'][0]['calibrated_probability']);
        $this->assertLessThanOrEqual(1, $fixture['proposals'][0]['calibrated_probability']);
    }

    public function test_v2_evaluation_fixture_has_a_trace_and_v1_is_not_promotable(): void
    {
        $v2 = json_decode((string) file_get_contents(base_path('contracts/fixtures/evaluation-result-v2.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('2.0', $v2['schema_version']);
        $this->assertArrayHasKey('decision_trace', $v2['proposals'][0]);
        $this->assertSame('blocked_by_strategy', $v2['proposals'][0]['resolution']);

        $this->assertContains('2.0', config('research.promotable_schema_versions'));
        $this->assertNotContains('1.0', config('research.promotable_schema_versions'));
    }

    public function test_shared_portfolio_and_order_contract_fixtures_have_stable_hashes(): void
    {
        $fixtures = [
            'portfolio-context-paper-v1.json' => 'context_hash',
            'portfolio-target-v1.json' => 'target_hash',
            'order-intent-v1.json' => 'intent_hash',
        ];

        foreach ($fixtures as $filename => $hashField) {
            $fixture = json_decode(
                (string) file_get_contents(base_path('contracts/fixtures/'.$filename)),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $expectedHash = $fixture[$hashField];
            unset($fixture[$hashField]);
            $this->sortRecursively($fixture);

            $this->assertSame('1.0', $fixture['schema_version']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $expectedHash);
            $this->assertSame(
                $expectedHash,
                hash('sha256', json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            );
            $this->assertFiniteNumbers($fixture);
        }
    }

    private function sortRecursively(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortRecursively($child);
            }
        }
    }

    private function assertFiniteNumbers(mixed $value): void
    {
        if (is_float($value)) {
            $this->assertTrue(is_finite($value));

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $child) {
            $this->assertFiniteNumbers($child);
        }
    }

    private function account(): BrokerAccount
    {
        return BrokerAccount::query()->create([
            'broker' => 'coinbase', 'external_account_id' => uniqid('acct-', true), 'currency' => 'USD',
            'buying_power' => 10000, 'cash_balance' => 10000, 'equity' => 10000,
            'status' => 'active', 'snapshot_at' => now(),
        ]);
    }

    private function activatePinnedVersions(): void
    {
        StrategyVersion::query()->create([
            'name' => 'pipeline-test', 'version' => '1.0.0', 'schema_version' => '1.0',
            'engine_version' => '0.1.0', 'status' => 'active', 'content_hash' => hash('sha256', 'pipeline-strategy'),
            'definition_json' => ['family' => 'trend'], 'activated_at' => now(),
        ]);
        UniverseVersion::query()->create([
            'name' => 'pipeline-test', 'version' => '1.0.0', 'status' => 'active',
            'content_hash' => hash('sha256', 'pipeline-universe'), 'symbols_json' => ['BTC', 'ETH', 'SOL'], 'activated_at' => now(),
        ]);
    }

    private function asset(string $symbol): Asset
    {
        return Asset::query()->create([
            'broker' => 'coinbase', 'symbol' => $symbol, 'asset_type' => 'crypto',
            'is_tradable' => true, 'is_enabled' => true,
        ]);
    }
}
