<?php

namespace Tests\Feature\Trading;

use App\Jobs\ConsumeBacktestResultsJob;
use App\Models\Asset;
use App\Models\EngineResult;
use App\Models\StrategyVersion;
use App\Models\UniverseVersion;
use App\Services\Research\ExperimentService;
use App\Services\Research\HoldoutEvaluationService;
use App\Services\Research\HoldoutGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyExperimentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthetic_experiment_runs_from_preregistration_to_immutable_holdout_result(): void
    {
        $universe = UniverseVersion::query()->create([
            'name' => 'workflow', 'version' => 'v1', 'status' => 'research',
            'content_hash' => hash('sha256', 'workflow-universe'), 'symbols_json' => ['BTC', 'ETH', 'SOL'],
        ]);
        $assets = collect(['BTC', 'ETH', 'SOL'])->map(fn (string $symbol): array => Asset::query()->create([
            'broker' => 'coinbase', 'symbol' => $symbol, 'asset_type' => 'crypto', 'is_tradable' => true, 'is_enabled' => true,
        ])->only(['id', 'symbol']));
        $experiment = app(ExperimentService::class)->create([
            'name' => 'synthetic-end-to-end',
            'universe_version_id' => $universe->id,
            'development_start' => '2019-01-01T00:00:00Z',
            'development_end' => '2025-01-01T00:00:00Z',
            'holdout_start' => '2025-01-01T00:00:00Z',
            'holdout_end' => '2026-01-01T00:00:00Z',
            'assets' => $assets->all(),
            'seeds' => [7],
        ])->load(['candidates', 'runs.engineJob', 'holdoutInterval']);

        $candidate = $experiment->candidates->firstOrFail();
        $developmentRun = $candidate->runs()->with('engineJob')->firstOrFail();
        $developmentJob = $developmentRun->engineJob;
        $developmentManifest = ['start' => '2019-01-01T00:00:00+00:00', 'end' => '2025-01-01T00:00:00+00:00', 'rows' => ['BTC' => ['1h' => 1000]]];
        $developmentHash = hash('sha256', json_encode($developmentManifest, JSON_THROW_ON_ERROR));
        $developmentLineage = $developmentJob->payload_json['lineage'] + ['manifest_hash' => $developmentHash];
        EngineResult::query()->create([
            'engine_job_id' => $developmentJob->id,
            'result_kind' => 'backtest',
            'engine_version' => $developmentLineage['engine_version'],
            'schema_version' => $developmentJob->schema_version,
            'as_of' => $developmentJob->as_of,
            'manifest_hash' => $developmentHash,
            'payload_json' => [
                'lineage' => $developmentLineage,
                'manifest' => $developmentManifest,
                'manifest_hash' => $developmentHash,
                'aggregate_metrics' => ['total_return_pct' => 8.0, 'max_drawdown_pct' => 7.0, 'trade_count' => 120],
                'stressed_metrics' => ['total_return_pct' => 2.0, 'max_drawdown_pct' => 12.0],
                'folds' => collect(range(1, 4))->map(fn (int $fold): array => ['fold' => $fold, 'normal' => ['total_return_pct' => 2.0], 'stressed' => ['total_return_pct' => 0.5]])->all(),
                'attribution' => ['asset' => ['BTC' => 40, 'ETH' => 35, 'SOL' => 25]],
                'benchmarks' => ['btc' => ['return_pct' => 3.0]],
                'cost_attribution' => ['fees_usd' => 50.0],
                'gate' => ['status' => 'passed', 'reasons' => []],
            ],
        ]);
        app(ConsumeBacktestResultsJob::class)->handle();

        $developmentRun->refresh();
        $this->assertSame('completed', $developmentRun->status);
        $this->assertSame($developmentHash, $developmentRun->result_manifest_hash);
        $this->assertGreaterThanOrEqual(4, $developmentRun->metrics()->where('metric_group', 'fold')->distinct('dimension_key')->count('dimension_key'));

        $finalist = StrategyVersion::query()->create([
            'name' => 'synthetic-finalist',
            'version' => 'final-v1',
            'schema_version' => '2.0',
            'engine_version' => (string) config('research.engine.version'),
            'status' => 'frozen',
            'content_hash' => hash('sha256', 'synthetic-finalist'),
            'definition_json' => $candidate->specification_json['resolved_baseline'],
            'strategy_experiment_candidate_id' => $candidate->id,
            'version_role' => 'final',
            'calibration_start' => $experiment->development_start,
            'calibration_end' => $experiment->development_end,
            'is_deployable' => true,
        ]);
        $holdoutJob = app(HoldoutEvaluationService::class)->authorizeAndEnqueue(
            $finalist->content_hash,
            $developmentRun->researchManifest->content_hash,
            'workflow-test',
            'single synthetic holdout',
        );
        app(HoldoutGuard::class)->recordAccess($holdoutJob);

        $holdoutManifest = ['start' => '2025-01-01T00:00:00+00:00', 'end' => '2026-01-01T00:00:00+00:00', 'rows' => ['BTC' => ['1h' => 500]]];
        $holdoutHash = hash('sha256', json_encode($holdoutManifest, JSON_THROW_ON_ERROR));
        $holdoutLineage = $holdoutJob->payload_json['lineage'];
        $holdoutLineage['manifest_hash'] = $holdoutHash;
        EngineResult::query()->create([
            'engine_job_id' => $holdoutJob->id,
            'result_kind' => 'holdout',
            'engine_version' => $holdoutLineage['engine_version'],
            'schema_version' => $holdoutJob->schema_version,
            'as_of' => $holdoutJob->as_of,
            'manifest_hash' => $holdoutHash,
            'payload_json' => [
                'lineage' => $holdoutLineage,
                'manifest' => $holdoutManifest,
                'manifest_hash' => $holdoutHash,
                'aggregate_metrics' => ['total_return_pct' => 4.0, 'max_drawdown_pct' => 8.0],
                'stressed_metrics' => ['total_return_pct' => 1.0, 'max_drawdown_pct' => 12.0],
                'holdout_gate' => ['status' => 'passed', 'checks' => ['complete' => true, 'reconciled' => true]],
            ],
        ]);
        app(ConsumeBacktestResultsJob::class)->handle();

        $interval = $experiment->holdoutInterval()->firstOrFail();
        $this->assertSame('passed', $interval->status->value);
        $this->assertSame($finalist->id, $interval->authorized_strategy_version_id);
        $this->assertSame(1, $interval->accessEvents()->where('event_type', 'terminal_passed')->count());
        $this->assertDatabaseHas('backtest_runs', ['id' => $holdoutJob->backtest_run_id, 'evaluation_stage' => 'holdout', 'status' => 'completed']);
    }
}
