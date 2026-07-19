<?php

namespace Tests\Feature\Trading;

use App\Data\MarketData\CommonMarketBar;
use App\Jobs\ConsumeEngineResultsJob;
use App\Jobs\RunPipelineCycleJob;
use App\Models\Asset;
use App\Models\AssetEvaluation;
use App\Models\BrokerAccount;
use App\Models\BrokerCredential;
use App\Models\DataQualityIncident;
use App\Models\EngineJob;
use App\Models\EngineResult;
use App\Models\MarketCandle;
use App\Models\MarketCandleRevision;
use App\Models\PaperSession;
use App\Models\StrategyRun;
use App\Services\Broker\Coinbase\CoinbaseClient;
use App\Services\Broker\Coinbase\CoinbaseJwtSigner;
use App\Services\MarketData\CandleIngestionService;
use App\Services\MarketData\CanonicalMarketEvidenceService;
use App\Services\MarketData\MarketEvidenceRepairService;
use App\Services\Operations\PipelineCycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MarketEvidenceReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_coinbase_one_hour_requests_cannot_fall_through_to_daily_granularity(): void
    {
        CarbonImmutable::setTestNow('2026-07-16 12:05:00 UTC');
        $signer = Mockery::mock(CoinbaseJwtSigner::class);
        $signer->shouldReceive('buildToken')->andReturn('jwt-token');
        $this->app->instance(CoinbaseJwtSigner::class, $signer);
        Http::fake([
            'https://api.coinbase.com/api/v3/brokerage/products/BTC-USD/candles*' => Http::response([
                'candles' => [[
                    'start' => '1784203200',
                    'low' => '99',
                    'high' => '102',
                    'open' => '100',
                    'close' => '101',
                    'volume' => '25',
                ]],
            ]),
        ]);
        $credential = new BrokerCredential([
            'broker' => 'coinbase', 'label' => 'test', 'api_key_ref' => 'private', 'secret_ref' => 'key', 'status' => 'active',
        ]);

        $rows = app(CoinbaseClient::class)->getCandles($credential, 'BTC', '1h');

        $this->assertCount(1, $rows);
        $this->assertSame(3600, (int) abs(CarbonImmutable::parse($rows[0]['close_time'])->diffInSeconds(CarbonImmutable::parse($rows[0]['open_time']))));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'granularity=ONE_HOUR'));
    }

    public function test_ingestion_normalizes_utc_and_preserves_first_seen_across_revisions(): void
    {
        $asset = $this->asset('BTC');
        $ingestion = app(CandleIngestionService::class);
        CarbonImmutable::setTestNow('2026-07-16 12:00:00 UTC');
        $base = [
            'open_time' => '2026-07-16T05:00:00-05:00',
            'close_time' => '2026-07-16T06:00:00-05:00',
            'open' => 100, 'high' => 102, 'low' => 99, 'close' => 101, 'volume' => 5, 'is_final' => true,
        ];
        $ingestion->ingest($asset, '1h', [$base]);
        CarbonImmutable::setTestNow('2026-07-16 13:00:00 UTC');
        $ingestion->ingest($asset, '1h', [[...$base, 'close' => 101.5]]);

        $candle = MarketCandle::query()->sole();
        $this->assertSame('2026-07-16T10:00:00+00:00', $candle->candle_open_time->utc()->toIso8601String());
        $this->assertSame('2026-07-16T12:00:00+00:00', $candle->first_seen_at->utc()->toIso8601String());
        $this->assertSame(2, $candle->source_revision);
    }

    public function test_four_hour_evidence_requires_every_asset_to_have_four_complete_hours(): void
    {
        CarbonImmutable::setTestNow('2026-07-16 05:00:00 UTC');
        $btc = $this->asset('BTC');
        $eth = $this->asset('ETH');
        $ingestion = app(CandleIngestionService::class);
        $evidence = app(CanonicalMarketEvidenceService::class);
        $start = CarbonImmutable::parse('2026-07-16T00:00:00Z');

        $ingestion->ingest($btc, '1h', $this->hourlyRows($start, 4));
        $evidence->rebuildDerivedForAsset($btc, $start, $start->addHours(4));
        $this->assertNull($evidence->latestCommonEligibleBar(Asset::query()->whereKey([$btc->id, $eth->id])->get(), now()->utc(), false));

        $ingestion->ingest($eth, '1h', $this->hourlyRows($start, 4));
        $evidence->rebuildDerivedForAsset($eth, $start, $start->addHours(4));
        $common = $evidence->latestCommonEligibleBar(Asset::query()->whereKey([$btc->id, $eth->id])->get(), now()->utc(), false);

        $this->assertNotNull($common);
        $this->assertSame('2026-07-16T04:00:00+00:00', $common->logicalBarClose->toIso8601String());
        $this->assertCount(2, $common->candleIdsByAsset);
    }

    public function test_logical_bar_requests_reuse_one_cycle_and_dispatch_once(): void
    {
        Queue::fake();
        $account = $this->account();
        $session = $this->paperSession($account);
        $close = CarbonImmutable::parse('2026-07-16T04:00:00Z');
        $bar = new CommonMarketBar($close, $close->addMinutes(2), [1, 2], [1 => 10, 2 => 20]);
        $cycles = app(PipelineCycleService::class);

        $first = $cycles->requestForBar($account, $session, $bar);
        $second = $cycles->requestForBar($account, $session, $bar);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('pipeline_cycles', 1);
        $this->assertDatabaseCount('pipeline_cycle_steps', 6);
        Queue::assertPushed(RunPipelineCycleJob::class, 1);
    }

    public function test_cycle_completion_fails_and_closes_every_nonterminal_step(): void
    {
        Queue::fake();
        $cycle = app(PipelineCycleService::class)->request($this->account(), 'manual', 'paper', 'terminal-invariant');

        app(PipelineCycleService::class)->complete($cycle, ['explanation' => 'invalid early completion']);

        $this->assertSame('failed', $cycle->fresh()->status);
        $this->assertSame(0, $cycle->steps()->whereIn('status', ['queued', 'running', 'waiting_engine'])->count());
        $this->assertSame(6, $cycle->steps()->where('status', 'failed')->count());
    }

    public function test_expired_result_persists_every_evaluation_but_creates_no_decision(): void
    {
        CarbonImmutable::setTestNow('2026-07-16 06:00:00 UTC');
        $account = $this->account();
        $assets = collect([$this->asset('BTC'), $this->asset('ETH')]);
        [$job, $result] = $this->engineResult($account, $assets->all(), [
            ['asset_id' => $assets[0]->id, 'action' => 'ENTER', 'score' => 0.8, 'calibrated_probability' => 0.7, 'warnings' => []],
            ['asset_id' => $assets[1]->id, 'action' => 'HOLD', 'score' => 0.1, 'calibrated_probability' => 0.51, 'warnings' => []],
        ], validUntil: now()->subMinute());

        app()->call([new ConsumeEngineResultsJob, 'handle']);

        $this->assertDatabaseCount('asset_evaluations', 2);
        $this->assertDatabaseCount('trade_decisions', 0);
        $this->assertSame('expired_result', AssetEvaluation::query()->where('asset_id', $assets[0]->id)->value('action_suppression_reason'));
        $this->assertSame('hold', AssetEvaluation::query()->where('asset_id', $assets[1]->id)->value('action_suppression_reason'));
        $this->assertNotNull($result->fresh()->consumed_at);
        $this->assertSame('completed', StrategyRun::query()->findOrFail((int) data_get($job->payload_json, 'strategy_run_id'))->status);
    }

    public function test_incomplete_engine_output_records_missing_asset_and_fails_cycle_terminally(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-07-16 04:05:00 UTC');
        $account = $this->account();
        $assets = [$this->asset('BTC'), $this->asset('ETH')];
        $cycle = app(PipelineCycleService::class)->request($account, 'manual', 'paper', 'incomplete-output');
        $cycle->steps()->whereIn('step_key', ['data_refresh', 'eligibility'])->update(['status' => 'completed', 'completed_at' => now()]);
        $cycle->steps()->where('step_key', 'evaluation')->update(['status' => 'waiting_engine']);
        $cycle->update(['status' => 'waiting_engine', 'current_step' => 'evaluation', 'logical_bar_close' => now()->startOfHour(), 'evidence_cutoff' => now()]);
        $this->engineResult($account, $assets, [
            ['asset_id' => $assets[0]->id, 'action' => 'ENTER', 'score' => 0.8, 'calibrated_probability' => 0.7, 'warnings' => []],
        ], pipelineCycleId: $cycle->id, validUntil: now()->addMinutes(10));

        app()->call([new ConsumeEngineResultsJob, 'handle']);

        $this->assertDatabaseCount('asset_evaluations', 2);
        $this->assertDatabaseCount('trade_decisions', 0);
        $this->assertDatabaseHas('asset_evaluations', ['asset_id' => $assets[1]->id, 'action' => 'HOLD', 'eligible' => false, 'action_suppression_reason' => 'incomplete_engine_output']);
        $this->assertSame('failed', $cycle->fresh()->status);
        $this->assertSame(0, $cycle->steps()->whereIn('status', ['queued', 'running', 'waiting_engine'])->count());
    }

    public function test_repair_command_is_read_only_without_execute(): void
    {
        $asset = $this->asset('BTC');
        MarketCandle::query()->create([
            'asset_id' => $asset->id, 'symbol' => 'BTC', 'timeframe' => '1h',
            'candle_open_time' => '2026-07-01 00:00:00', 'candle_close_time' => '2026-07-02 00:00:00',
            'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1,
            'source' => 'coinbase', 'ingested_at' => now(), 'first_seen_at' => now(), 'available_at' => now(),
            'is_final' => true, 'source_revision' => 1, 'content_hash' => hash('sha256', 'bad-hour'), 'quality_state' => 'valid',
        ]);
        $this->artisan('research:repair-market-evidence', ['--asset' => ['BTC']])
            ->expectsOutputToContain('Dry run only. No rows were changed.')
            ->assertSuccessful();

        $this->assertDatabaseCount('market_candles', 1);
        $this->assertDatabaseCount('market_candle_revisions', 0);
        $this->assertSame('valid', MarketCandle::query()->sole()->quality_state);
    }

    public function test_repair_execution_preserves_revisions_rebuilds_buckets_and_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-07-02 05:00:00 UTC');
        $asset = $this->asset('BTC');
        app(CandleIngestionService::class)->ingest($asset, '4h', [[
            'open_time' => '2026-07-01T00:00:00Z', 'close_time' => '2026-07-01T04:00:00Z',
            'open' => 100, 'high' => 105, 'low' => 99, 'close' => 104, 'volume' => 20,
            'turnover_usd' => 0, 'is_final' => true,
        ]]);
        MarketCandle::query()->create([
            'asset_id' => $asset->id, 'symbol' => 'BTC', 'timeframe' => '1h',
            'candle_open_time' => '2026-07-01 00:00:00', 'candle_close_time' => '2026-07-02 00:00:00',
            'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1,
            'source' => 'coinbase', 'ingested_at' => now(), 'first_seen_at' => now(), 'available_at' => now(),
            'is_final' => true, 'source_revision' => 1, 'content_hash' => hash('sha256', 'bad-hour'), 'quality_state' => 'valid',
        ]);
        MarketCandle::query()->create([
            'asset_id' => $asset->id, 'symbol' => 'BTC', 'timeframe' => '1h',
            'candle_open_time' => '2026-07-01 02:00:00', 'candle_close_time' => '2026-07-01 03:00:00',
            'open' => 102, 'high' => 104, 'low' => 101, 'close' => 103, 'volume' => 5,
            'source' => 'coinbase_public', 'ingested_at' => now(), 'first_seen_at' => now(), 'available_at' => now(),
            'is_final' => true, 'source_revision' => 1, 'content_hash' => hash('sha256', 'legacy-public'), 'quality_state' => 'valid',
        ]);
        $start = CarbonImmutable::parse('2026-07-01T00:00:00Z');
        $end = $start->addHours(4);
        $exchangeRows = collect(range(0, 3))->map(function (int $offset) use ($start): array {
            return [$start->addHours($offset)->getTimestamp(), 99 + $offset, 102 + $offset, 100 + $offset, 101 + $offset, 5];
        })->reverse()->values()->all();
        Http::fake(['https://api.exchange.coinbase.com/products/BTC-USD/candles*' => Http::response($exchangeRows)]);
        $repairs = app(MarketEvidenceRepairService::class);
        $plan = $repairs->plan(['BTC'], $start, $end);

        $result = $repairs->execute($plan);

        $this->assertSame(1, $result['batches_completed']);
        $this->assertGreaterThanOrEqual(1, $result['rows_invalidated']);
        $this->assertGreaterThanOrEqual(1, $result['aggregates_rebuilt']);
        $this->assertSame(4, MarketCandle::query()->where('source', 'coinbase')->where('timeframe', '1h')->where('quality_state', 'valid')->count());
        $derived = MarketCandle::query()->where('source', 'coinbase')->where('timeframe', '4h')->where('quality_state', 'valid')->sole();
        $this->assertSame('1h', data_get($derived->metadata_json, 'derived_from'));
        $this->assertDatabaseHas('market_candles', ['source' => 'coinbase_public', 'quality_state' => 'invalid']);
        $this->assertGreaterThanOrEqual(1, MarketCandleRevision::query()->count());
        $this->assertDatabaseHas('data_quality_incidents', ['incident_type' => 'market_evidence_repaired']);
        $this->assertSame([], $repairs->plan(['BTC'], $start, $end)['assets']);
    }

    public function test_repair_preserves_a_coinbase_source_gap_without_synthesizing_prices(): void
    {
        CarbonImmutable::setTestNow('2026-07-02 05:00:00 UTC');
        $asset = $this->asset('BTC');
        $start = CarbonImmutable::parse('2026-07-01T00:00:00Z');
        $end = $start->addHours(4);
        $exchangeRows = collect([0, 1, 3])->map(function (int $offset) use ($start): array {
            return [$start->addHours($offset)->getTimestamp(), 99 + $offset, 102 + $offset, 100 + $offset, 101 + $offset, 5];
        })->reverse()->values()->all();
        Http::fake(['https://api.exchange.coinbase.com/products/BTC-USD/candles*' => Http::response($exchangeRows)]);
        $priorFailure = DataQualityIncident::query()->create([
            'source' => 'coinbase',
            'stream' => 'candles:repair:BTC',
            'severity' => 'error',
            'incident_type' => 'market_evidence_repair_failed',
            'started_at' => now()->subMinute(),
            'message' => 'Earlier attempt failed.',
            'context_json' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()],
        ]);

        $result = app(MarketEvidenceRepairService::class)->execute([
            'assets' => [[
                'asset_id' => $asset->id,
                'symbol' => $asset->symbol,
                'batches' => [['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()]],
            ]],
        ]);

        $this->assertSame(1, $result['batches_completed']);
        $this->assertSame(3, MarketCandle::query()->where('timeframe', '1h')->where('quality_state', 'valid')->count());
        $this->assertSame(0, MarketCandle::query()->where('timeframe', '4h')->where('quality_state', 'valid')->count());
        $this->assertDatabaseHas('data_quality_incidents', ['incident_type' => 'market_evidence_source_gap']);
        $incident = DataQualityIncident::query()->where('incident_type', 'market_evidence_source_gap')->sole();
        $this->assertSame(['2026-07-01T02:00:00+00:00'], data_get($incident->context_json, 'missing_open_times'));
        $this->assertNotNull($priorFailure->fresh()->resolved_at);
    }

    /** @return array<int, array<string, mixed>> */
    private function hourlyRows(CarbonImmutable $start, int $hours): array
    {
        return collect(range(0, $hours - 1))->map(function (int $offset) use ($start): array {
            $open = $start->addHours($offset);

            return [
                'open_time' => $open->toIso8601String(), 'close_time' => $open->addHour()->toIso8601String(),
                'open' => 100 + $offset, 'high' => 102 + $offset, 'low' => 99 + $offset, 'close' => 101 + $offset,
                'volume' => 10, 'is_final' => true, 'available_at' => $open->addHour()->toIso8601String(),
            ];
        })->all();
    }

    /**
     * @param  array<int, Asset>  $assets
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array{EngineJob, EngineResult}
     */
    private function engineResult(BrokerAccount $account, array $assets, array $proposals, ?string $pipelineCycleId = null, mixed $validUntil = null): array
    {
        $run = StrategyRun::query()->create([
            'strategy_name' => 'test', 'mode' => 'paper', 'started_at' => now(), 'status' => 'waiting_result_consumption',
        ]);
        $barClose = now()->utc()->startOfHour();
        $job = EngineJob::query()->create([
            'id' => (string) Str::uuid(), 'schema_version' => '1.0', 'kind' => 'evaluate',
            'idempotency_key' => hash('sha256', Str::uuid()->toString()), 'as_of' => $barClose,
            'valid_until' => $barClose->addHours(4), 'status' => 'succeeded',
            'payload_json' => [
                'broker_account_id' => $account->id,
                'strategy_run_id' => $run->id,
                'assets' => collect($assets)->map(fn (Asset $asset): array => ['id' => $asset->id, 'symbol' => $asset->symbol])->all(),
                'mode' => 'paper', 'evaluation_kind' => 'trading',
                'logical_bar_close' => $barClose->toIso8601String(),
                'evidence_cutoff' => now()->toIso8601String(),
                'pipeline_cycle_id' => $pipelineCycleId,
            ],
        ]);
        $result = EngineResult::query()->create([
            'engine_job_id' => $job->id, 'result_kind' => 'evaluation', 'engine_version' => 'test', 'schema_version' => '1.0',
            'as_of' => $barClose, 'valid_until' => $validUntil ?? now()->addMinutes(10),
            'manifest_hash' => hash('sha256', 'manifest-'.$job->id), 'payload_json' => ['proposals' => $proposals],
        ]);

        return [$job, $result];
    }

    private function account(): BrokerAccount
    {
        return BrokerAccount::query()->create([
            'broker' => 'coinbase', 'external_account_id' => uniqid('reliability-', true), 'currency' => 'USD',
            'buying_power' => 10_000, 'cash_balance' => 10_000, 'equity' => 10_000, 'status' => 'active', 'snapshot_at' => now(),
        ]);
    }

    private function paperSession(BrokerAccount $account): PaperSession
    {
        return PaperSession::query()->create([
            'broker_account_id' => $account->id, 'funding_mode' => 'virtual', 'status' => 'active', 'currency' => 'USD',
            'opening_cash' => 10_000, 'reserved_cash' => 0, 'fee_scenario' => 'test', 'slippage_scenario' => 'test',
            'valuation_at' => now(), 'started_at' => now(),
        ]);
    }

    private function asset(string $symbol): Asset
    {
        return Asset::query()->create([
            'broker' => 'coinbase', 'symbol' => $symbol, 'asset_type' => 'crypto', 'is_tradable' => true, 'is_enabled' => true,
        ]);
    }
}
