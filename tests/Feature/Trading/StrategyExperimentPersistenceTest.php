<?php

namespace Tests\Feature\Trading;

use App\Enums\EvaluationStage;
use App\Enums\ExperimentStatus;
use App\Jobs\ConsumeBacktestResultsJob;
use App\Models\Asset;
use App\Models\EngineResult;
use App\Models\StrategyExperiment;
use App\Models\UniverseVersion;
use App\Services\Research\ExperimentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class StrategyExperimentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_preregisters_immutable_candidates_and_enqueues_stage_aware_runs(): void
    {
        $universe = $this->universe();
        $assets = collect(['BTC', 'ETH', 'SOL'])->map(fn (string $symbol): Asset => Asset::query()->create([
            'broker' => 'coinbase',
            'symbol' => $symbol,
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]));

        $experiment = app(ExperimentService::class)->create([
            'name' => 'bounded-family-comparison',
            'universe_version_id' => $universe->id,
            'development_start' => '2019-01-01T00:00:00Z',
            'development_end' => '2025-01-01T00:00:00Z',
            'holdout_start' => '2025-01-01T00:00:00Z',
            'holdout_end' => '2026-01-01T00:00:00Z',
            'initial_capital' => 100_000,
            'seeds' => [7, 11],
            'assets' => $assets->map->only(['id', 'symbol'])->all(),
        ]);

        $this->assertSame(ExperimentStatus::Queued, $experiment->status);
        $this->assertCount(4, $experiment->candidates);
        $this->assertCount(4, $experiment->runs);
        $this->assertSame(
            [EvaluationStage::Development],
            $experiment->runs->pluck('evaluation_stage')->unique()->values()->all(),
        );
        $this->assertCount(4, $experiment->runs->pluck('engineJob')->filter());
        $this->assertSame(
            $experiment->candidates->pluck('content_hash')->count(),
            $experiment->candidates->pluck('content_hash')->unique()->count(),
        );

        $this->expectException(LogicException::class);
        $experiment->update(['name' => 'rewritten-after-enqueue']);
    }

    public function test_result_consumption_verifies_lineage_and_persists_queryable_metric_groups(): void
    {
        $experiment = $this->experiment();
        $run = $experiment->runs()->with('engineJob')->firstOrFail();
        $job = $run->engineJob;
        $manifest = ['start' => '2019-01-01T00:00:00+00:00', 'end' => '2025-01-01T00:00:00+00:00', 'rows' => []];
        $manifestHash = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));

        EngineResult::query()->create([
            'engine_job_id' => $job->id,
            'result_kind' => 'backtest',
            'engine_version' => $job->payload_json['lineage']['engine_version'],
            'schema_version' => $job->schema_version,
            'as_of' => $job->as_of,
            'manifest_hash' => $manifestHash,
            'payload_json' => [
                'lineage' => $job->payload_json['lineage'] + ['manifest_hash' => $manifestHash],
                'manifest' => $manifest,
                'manifest_hash' => $manifestHash,
                'aggregate_metrics' => ['net_return_pct' => 8.5],
                'stressed_metrics' => ['net_return_pct' => 2.25],
                'folds' => [['fold' => 1, 'normal' => ['max_drawdown_pct' => 4.0]]],
                'attribution' => ['asset' => ['BTC' => 1250.0]],
                'benchmarks' => ['btc' => ['net_return_pct' => 3.0]],
                'cost_attribution' => ['fees_usd' => 35.0],
                'gate' => ['status' => 'passed', 'reasons' => []],
            ],
        ]);

        app(ConsumeBacktestResultsJob::class)->handle();

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertDatabaseHas('backtest_run_metrics', [
            'backtest_run_id' => $run->id,
            'metric_group' => 'fold',
            'metric_name' => 'max_drawdown_pct',
        ]);
        $this->assertDatabaseHas('backtest_run_metrics', [
            'backtest_run_id' => $run->id,
            'metric_group' => 'asset',
            'metric_name' => 'pnl:BTC',
        ]);
        $this->assertDatabaseHas('backtest_run_metrics', [
            'backtest_run_id' => $run->id,
            'metric_group' => 'benchmark',
            'metric_name' => 'net_return_pct:btc',
        ]);
    }

    private function experiment(): StrategyExperiment
    {
        $universe = $this->universe();
        $assets = collect(['BTC', 'ETH', 'SOL'])->map(fn (string $symbol): array => Asset::query()->create([
            'broker' => 'coinbase', 'symbol' => $symbol, 'asset_type' => 'crypto',
            'is_tradable' => true, 'is_enabled' => true,
        ])->only(['id', 'symbol']));

        return app(ExperimentService::class)->create([
            'name' => 'result-lineage',
            'universe_version_id' => $universe->id,
            'development_start' => '2019-01-01T00:00:00Z',
            'development_end' => '2025-01-01T00:00:00Z',
            'holdout_start' => '2025-01-01T00:00:00Z',
            'holdout_end' => '2026-01-01T00:00:00Z',
            'initial_capital' => 100_000,
            'seeds' => [7],
            'assets' => $assets->all(),
        ])->load(['runs.engineJob']);
    }

    private function universe(): UniverseVersion
    {
        return UniverseVersion::query()->create([
            'name' => 'coinbase-test',
            'version' => 'v1',
            'status' => 'research',
            'content_hash' => hash('sha256', uniqid('universe-', true)),
            'symbols_json' => ['BTC', 'ETH', 'SOL'],
            'rules_json' => ['quote' => 'USD'],
        ]);
    }
}
