<?php

namespace App\Services\Research;

use App\Enums\EvaluationStage;
use App\Enums\HoldoutStatus;
use App\Models\Asset;
use App\Models\BacktestRun;
use App\Models\EngineJob;
use App\Models\ResearchManifest;
use App\Models\StrategyVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HoldoutEvaluationService
{
    public function __construct(private readonly HoldoutGuard $guard) {}

    public function authorizeAndEnqueue(
        string $finalistHash,
        string $manifestHash,
        string $actor,
        string $purpose,
    ): EngineJob {
        $finalist = StrategyVersion::query()
            ->with(['candidate.experiment.universeVersion', 'candidate.experiment.holdoutInterval'])
            ->where('content_hash', $finalistHash)
            ->firstOrFail();
        $manifest = ResearchManifest::query()->where('content_hash', $manifestHash)->firstOrFail();
        $interval = $this->guard->authorize($finalist, $manifest, $actor, $purpose);
        if ($interval->engine_job_id !== null) {
            return EngineJob::query()->findOrFail($interval->engine_job_id);
        }

        return DB::transaction(function () use ($finalist, $manifest, $interval): EngineJob {
            $locked = $interval->newQuery()->whereKey($interval->id)->lockForUpdate()->firstOrFail();
            if ($locked->engine_job_id !== null) {
                return EngineJob::query()->findOrFail($locked->engine_job_id);
            }
            $experiment = $finalist->candidate->experiment;
            $universe = $experiment->universeVersion;
            $assets = $universe->memberships()->with('asset')->get()
                ->map(fn ($membership): array => [
                    'id' => $membership->asset_id,
                    'symbol' => $membership->asset->symbol,
                    'listed_at' => $membership->listed_at?->toIso8601String(),
                    'delisted_at' => $membership->delisted_at?->toIso8601String(),
                ]);
            if ($assets->isEmpty()) {
                $assets = Asset::query()->where('broker', 'coinbase')->whereIn('symbol', (array) $universe->symbols_json)
                    ->orderBy('symbol')->get(['id', 'symbol'])->map->toArray();
            }
            $spec = [
                'strategy_version' => $finalist->version,
                'universe_version' => $universe->version,
                'strategy_definition' => $finalist->definition_json,
                'start' => $locked->holdout_start->toIso8601String(),
                'end' => $locked->holdout_end->toIso8601String(),
                'warmup_start' => $locked->holdout_start->subDays(365)->toIso8601String(),
                'initial_capital' => 100_000.0,
                'random_seed' => (int) data_get($experiment->seeds_json, '0', 7),
                'fee_bps' => 60.0,
                'spread_bps' => 10.0,
                'slippage_bps' => 8.0,
            ];
            $run = BacktestRun::query()->create([
                'strategy_name' => $finalist->name,
                'strategy_version_id' => $finalist->id,
                'universe_version_id' => $universe->id,
                'research_manifest_id' => $manifest->id,
                'strategy_experiment_id' => $experiment->id,
                'strategy_experiment_candidate_id' => $finalist->strategy_experiment_candidate_id,
                'evaluation_stage' => EvaluationStage::Holdout,
                'execution_policy_hash' => $experiment->execution_policy_hash,
                'run_started_at' => now(),
                'timeframe_start' => $locked->holdout_start,
                'timeframe_end' => $locked->holdout_end,
                'status' => 'queued',
                'trigger' => 'holdout_authorization',
                'spec_json' => $spec,
                'holdout_locked' => false,
            ]);
            $lineage = [
                'engine_version' => $locked->engine_version,
                'strategy_hash' => $locked->candidate_hash,
                'universe_hash' => $universe->content_hash,
                'experiment_hash' => $experiment->content_hash,
                'execution_policy_hash' => $experiment->execution_policy_hash,
                'manifest_hash' => $manifest->content_hash,
                'code_hash' => $locked->code_hash,
            ];
            $payload = [
                'holdout_interval_id' => $locked->id,
                'backtest_run_id' => $run->id,
                'experiment_id' => $experiment->id,
                'candidate_id' => $finalist->strategy_experiment_candidate_id,
                'initial_state' => 'all_cash_flat',
                'assets' => $assets->values()->all(),
                'spec' => $spec,
                'lineage' => $lineage,
            ];
            $job = EngineJob::query()->create([
                'id' => (string) Str::uuid(),
                'schema_version' => (string) config('research.engine.schema_version'),
                'kind' => 'holdout',
                'strategy_version_id' => $finalist->id,
                'universe_version_id' => $universe->id,
                'research_manifest_id' => $manifest->id,
                'backtest_run_id' => $run->id,
                'strategy_experiment_id' => $experiment->id,
                'strategy_experiment_candidate_id' => $finalist->strategy_experiment_candidate_id,
                'idempotency_key' => $locked->authorization_idempotency_key,
                'as_of' => $locked->holdout_end,
                'payload_json' => $payload,
                'status' => 'pending',
                'max_attempts' => (int) config('research.engine.max_attempts', 3),
            ]);
            $locked->update(['engine_job_id' => $job->id]);

            return $job;
        });
    }

    /** @param array<string, mixed> $payload */
    public function recordResult(BacktestRun $run, array $payload): void
    {
        $status = HoldoutStatus::tryFrom((string) data_get($payload, 'holdout_gate.status'))
            ?? HoldoutStatus::tryFrom((string) ($payload['evidence_status'] ?? ''))
            ?? HoldoutStatus::Inconclusive;
        $interval = $run->experiment?->holdoutInterval;
        if ($interval !== null) {
            $this->guard->recordTerminal($interval, $status, [
                'backtest_run_id' => $run->id,
                'manifest_hash' => $run->result_manifest_hash,
                'gate' => $payload['holdout_gate'] ?? $payload['gate'] ?? [],
            ]);
        }
    }
}
