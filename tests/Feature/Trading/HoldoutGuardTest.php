<?php

namespace Tests\Feature\Trading;

use App\Jobs\ConsumeBacktestResultsJob;
use App\Models\Asset;
use App\Models\EngineResult;
use App\Models\ResearchManifest;
use App\Models\StrategyExperiment;
use App\Models\StrategyVersion;
use App\Models\UniverseVersion;
use App\Services\Research\ExperimentService;
use App\Services\Research\HoldoutEvaluationService;
use App\Services\Research\HoldoutGuard;
use App\Services\Research\ResearchStatusService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldoutGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_experiment_anchors_a_locked_interval_hidden_from_development_access(): void
    {
        $experiment = $this->experiment('anchored');

        $this->assertSame('locked', $experiment->holdoutInterval->status->value);
        $this->assertSame('2025-01-01T00:00:00+00:00', $experiment->holdoutInterval->holdout_start->toIso8601String());
        $this->assertSame('2026-01-01T00:00:00+00:00', $experiment->holdoutInterval->holdout_end->toIso8601String());

        $this->expectException(DomainException::class);
        app(HoldoutGuard::class)->assertDevelopmentWindowAllowed(
            $experiment->holdout_start,
            $experiment->holdout_end,
        );
    }

    public function test_only_one_frozen_finalist_can_open_the_interval_and_exact_retries_are_idempotent(): void
    {
        $experiment = $this->experiment('single-finalist');
        $manifest = $this->manifest($experiment);
        $finalist = $this->finalist($experiment, 0);

        $first = app(HoldoutEvaluationService::class)->authorizeAndEnqueue(
            $finalist->content_hash,
            $manifest->content_hash,
            'local:test',
            'single synthetic holdout evaluation',
        );
        $retry = app(HoldoutEvaluationService::class)->authorizeAndEnqueue(
            $finalist->content_hash,
            $manifest->content_hash,
            'local:test',
            'single synthetic holdout evaluation',
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertSame('holdout', $first->kind);
        $this->assertSame('all_cash_flat', $first->payload_json['initial_state']);
        $this->assertSame(1, $experiment->holdoutInterval->accessEvents()->where('event_type', 'authorized')->count());

        $otherFinalist = $this->finalist($experiment, 1);
        $this->expectException(DomainException::class);
        app(HoldoutEvaluationService::class)->authorizeAndEnqueue(
            $otherFinalist->content_hash,
            $manifest->content_hash,
            'local:test',
            'attempt to replace frozen finalist',
        );
    }

    public function test_revealed_interval_permanently_blocks_an_overlapping_future_experiment(): void
    {
        $firstExperiment = $this->experiment('first-revealed');
        $firstManifest = $this->manifest($firstExperiment);
        $firstFinalist = $this->finalist($firstExperiment, 0);
        $firstJob = app(HoldoutEvaluationService::class)->authorizeAndEnqueue(
            $firstFinalist->content_hash,
            $firstManifest->content_hash,
            'local:test',
            'reveal first interval',
        );
        app(HoldoutGuard::class)->recordAccess($firstJob);

        $overlap = $this->experiment('overlap', '2024-07-01T00:00:00Z', '2025-07-01T00:00:00Z', '2026-07-01T00:00:00Z');
        $overlapManifest = $this->manifest($overlap);
        $overlapFinalist = $this->finalist($overlap, 0);

        $this->expectException(DomainException::class);
        app(HoldoutEvaluationService::class)->authorizeAndEnqueue(
            $overlapFinalist->content_hash,
            $overlapManifest->content_hash,
            'local:test',
            'overlapping future interval',
        );
    }

    public function test_holdout_result_consumption_records_exactly_one_terminal_outcome(): void
    {
        $experiment = $this->experiment('terminal-result');
        $manifest = $this->manifest($experiment);
        $finalist = $this->finalist($experiment, 0);
        $job = app(HoldoutEvaluationService::class)->authorizeAndEnqueue(
            $finalist->content_hash,
            $manifest->content_hash,
            'local:test',
            'terminal result test',
        );
        app(HoldoutGuard::class)->recordAccess($job);
        $holdoutManifest = ['start' => '2025-01-01T00:00:00+00:00', 'end' => '2026-01-01T00:00:00+00:00'];
        $holdoutManifestHash = hash('sha256', json_encode($holdoutManifest, JSON_THROW_ON_ERROR));
        $lineage = $job->payload_json['lineage'];
        $lineage['manifest_hash'] = $holdoutManifestHash;
        EngineResult::query()->create([
            'engine_job_id' => $job->id,
            'result_kind' => 'holdout',
            'engine_version' => $lineage['engine_version'],
            'schema_version' => $job->schema_version,
            'as_of' => $job->as_of,
            'manifest_hash' => $holdoutManifestHash,
            'payload_json' => [
                'lineage' => $lineage,
                'manifest' => $holdoutManifest,
                'manifest_hash' => $holdoutManifestHash,
                'aggregate_metrics' => ['total_return_pct' => 4.0, 'max_drawdown_pct' => 8.0],
                'stressed_metrics' => ['total_return_pct' => 1.0, 'max_drawdown_pct' => 12.0],
                'holdout_gate' => ['status' => 'passed', 'checks' => ['complete' => true]],
            ],
        ]);

        app(ConsumeBacktestResultsJob::class)->handle();

        $interval = $experiment->holdoutInterval()->firstOrFail();
        $this->assertSame('passed', $interval->status->value);
        $this->assertSame(1, $interval->accessEvents()->where('event_type', 'terminal_passed')->count());
        $this->expectException(\LogicException::class);
        $interval->update(['purpose' => 'rewritten terminal result']);
    }

    public function test_nonterminal_holdout_values_are_redacted_from_research_status(): void
    {
        $experiment = $this->experiment('redacted-status');
        $manifest = $this->manifest($experiment);
        $finalist = $this->finalist($experiment, 0);
        app(HoldoutEvaluationService::class)->authorizeAndEnqueue(
            $finalist->content_hash,
            $manifest->content_hash,
            'local:test',
            'redaction test',
        );

        $payload = json_encode(app(ResearchStatusService::class)->backtests(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('2026-01-01', $payload);
        $this->assertStringNotContainsString('all_cash_flat', $payload);
    }

    private function experiment(
        string $name,
        string $developmentEnd = '2025-01-01T00:00:00Z',
        string $holdoutStart = '2025-01-01T00:00:00Z',
        string $holdoutEnd = '2026-01-01T00:00:00Z',
    ): StrategyExperiment {
        $universe = UniverseVersion::query()->create([
            'name' => $name,
            'version' => 'v1',
            'status' => 'research',
            'content_hash' => hash('sha256', 'universe-'.$name),
            'symbols_json' => ['BTC', 'ETH', 'SOL'],
            'rules_json' => ['quote' => 'USD'],
        ]);
        $assets = collect(['BTC', 'ETH', 'SOL'])->map(function (string $symbol): array {
            $asset = Asset::query()->firstOrCreate(
                ['broker' => 'coinbase', 'symbol' => $symbol],
                ['asset_type' => 'crypto', 'is_tradable' => true, 'is_enabled' => true],
            );

            return $asset->only(['id', 'symbol']);
        });

        return app(ExperimentService::class)->create([
            'name' => $name,
            'universe_version_id' => $universe->id,
            'development_start' => '2019-01-01T00:00:00Z',
            'development_end' => $developmentEnd,
            'holdout_start' => $holdoutStart,
            'holdout_end' => $holdoutEnd,
            'initial_capital' => 100_000,
            'seeds' => [7],
            'assets' => $assets->all(),
        ])->load(['candidates', 'holdoutInterval']);
    }

    private function finalist(StrategyExperiment $experiment, int $candidateIndex): StrategyVersion
    {
        $candidate = $experiment->candidates->values()->get($candidateIndex);
        $definition = $candidate->specification_json['resolved_baseline'];

        return StrategyVersion::query()->create([
            'name' => $experiment->name.'-'.$candidate->family,
            'version' => 'final-'.$candidate->id,
            'schema_version' => '2.0',
            'engine_version' => (string) config('research.engine.version'),
            'status' => 'frozen',
            'content_hash' => hash('sha256', 'finalist-'.$candidate->id),
            'definition_json' => $definition,
            'strategy_experiment_candidate_id' => $candidate->id,
            'version_role' => 'final',
            'calibration_start' => $experiment->development_start,
            'calibration_end' => $experiment->development_end,
            'is_deployable' => true,
        ]);
    }

    private function manifest(StrategyExperiment $experiment): ResearchManifest
    {
        $manifest = ResearchManifest::query()->create([
            'kind' => 'backtest',
            'schema_version' => '2.0',
            'content_hash' => hash('sha256', 'manifest-'.$experiment->id),
            'source_window_start' => $experiment->development_start,
            'source_window_end' => $experiment->development_end,
            'row_count' => 1000,
            'inputs_json' => ['experiment_hash' => $experiment->content_hash],
            'quality_json' => ['complete' => true],
            'frozen_at' => now(),
        ]);
        $experiment->runs()->firstOrFail()->update([
            'research_manifest_id' => $manifest->id,
            'status' => 'completed',
            'result_json' => ['gate' => ['status' => 'passed']],
        ]);

        return $manifest;
    }
}
