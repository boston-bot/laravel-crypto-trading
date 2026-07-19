<?php

namespace App\Jobs;

use App\Contracts\StrategyEngine;
use App\Data\Research\EvaluationRequest;
use App\Enums\BrokerType;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\EngineJob;
use App\Models\PipelineCycle;
use App\Models\StrategyRun;
use App\Services\Operations\PipelineCycleService;
use App\Services\Strategy\SkillCatalogService;
use App\Services\Strategy\UniverseSelectionService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

class EvaluateSignalsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $brokerAccountId = null,
        public readonly ?string $mode = null,
        public readonly ?string $pipelineCycleId = null,
        public readonly string $evaluationKind = 'trading',
        public readonly ?string $logicalBarClose = null,
    ) {}

    public function handle(
        Dispatcher $dispatcher,
        StrategyEngine $strategyEngine,
        SkillCatalogService $skillCatalogService,
        UniverseSelectionService $universeSelectionService,
        PipelineCycleService $cycles,
    ): void {
        $brokerAccount = $this->resolveBrokerAccount();
        if ($brokerAccount === null) {
            return;
        }

        $cycle = $this->pipelineCycleId !== null ? PipelineCycle::query()->find($this->pipelineCycleId) : null;
        $mode = $this->mode ?? (string) config('broker.mode', 'paper');
        $assets = $this->resolveAssets($cycle, $universeSelectionService);
        $now = CarbonImmutable::now('UTC');
        $logicalBarClose = $this->logicalBarClose !== null
            ? CarbonImmutable::parse($this->logicalBarClose, 'UTC')->utc()
            : $now->setTime((int) (floor($now->hour / 4) * 4), 0);
        $evidenceCutoff = $cycle?->evidence_cutoff !== null
            ? CarbonImmutable::instance($cycle->evidence_cutoff)->utc()
            : $now;

        $requestTemplate = new EvaluationRequest(
            brokerAccountId: $brokerAccount->id,
            strategyRunId: 0,
            asOf: $this->evaluationKind === 'trading' ? $logicalBarClose : $now,
            assets: $assets->map(fn (Asset $asset): array => ['id' => $asset->id, 'symbol' => $asset->symbol])->all(),
            strategyVersionId: $cycle?->strategy_version_id,
            universeVersionId: $cycle?->universe_version_id,
            schemaVersion: (string) config('research.engine.schema_version', '1.0'),
            mode: $mode,
            evaluationKind: $this->evaluationKind,
            logicalBarClose: $logicalBarClose,
            pipelineCycleId: $this->pipelineCycleId,
            evidenceCutoff: $evidenceCutoff,
        );
        $existingJob = EngineJob::query()->where('idempotency_key', $requestTemplate->idempotencyKey())->first();
        if ($existingJob !== null) {
            $this->suppressDuplicateCycle($cycle, $cycles, $existingJob);

            return;
        }

        $strategyRun = StrategyRun::query()->create([
            'strategy_name' => (string) config('trading.strategy_name'),
            'mode' => $mode,
            'started_at' => now(),
            'account_equity' => $brokerAccount->equity,
            'status' => 'running',
        ]);
        $request = new EvaluationRequest(
            brokerAccountId: $requestTemplate->brokerAccountId,
            strategyRunId: $strategyRun->id,
            asOf: $requestTemplate->asOf,
            assets: $requestTemplate->assets,
            strategyVersionId: $requestTemplate->strategyVersionId,
            universeVersionId: $requestTemplate->universeVersionId,
            manifestId: $requestTemplate->manifestId,
            schemaVersion: $requestTemplate->schemaVersion,
            mode: $requestTemplate->mode,
            evaluationKind: $requestTemplate->evaluationKind,
            logicalBarClose: $requestTemplate->logicalBarClose,
            pipelineCycleId: $requestTemplate->pipelineCycleId,
            evidenceCutoff: $requestTemplate->evidenceCutoff,
        );
        $job = $strategyEngine->submit($request);

        $createdForRun = $job->wasRecentlyCreated || (int) data_get($job->payload_json, 'strategy_run_id', 0) === $strategyRun->id;
        if (! $createdForRun) {
            $strategyRun->delete();
            $this->suppressDuplicateCycle($cycle, $cycles, $job);

            return;
        }

        $pending = ! in_array($job->status, ['succeeded', 'failed', 'expired'], true);
        $strategyRun->update([
            'status' => $pending ? 'waiting_engine' : ($job->status === 'succeeded' ? 'waiting_result_consumption' : $job->status),
            'completed_at' => in_array($job->status, ['failed', 'expired'], true) ? now() : null,
            'summary_json' => [
                'engine_job_id' => $job->id,
                'engine_driver' => (string) config('research.engine.driver', 'legacy'),
                'assets_submitted' => $assets->count(),
                'as_of' => $request->asOf->toIso8601String(),
                'evidence_cutoff' => $evidenceCutoff->toIso8601String(),
                'logical_bar_close' => $logicalBarClose->toIso8601String(),
                'evaluation_kind' => $this->evaluationKind,
                'pipeline_cycle_id' => $this->pipelineCycleId,
            ],
            'skill_outputs_json' => ['skills' => $skillCatalogService->listLocalSkills()],
        ]);

        if ($cycle !== null && $pending) {
            $cycles->waitForEngine($cycle, $job->id);
        }

        if ($job->status === 'succeeded') {
            $dispatcher->dispatchSync(new ConsumeEngineResultsJob);
        }
    }

    private function resolveBrokerAccount(): ?BrokerAccount
    {
        $query = BrokerAccount::query()->where('broker', BrokerType::default()->value);
        if ($this->brokerAccountId !== null) {
            $query->whereKey($this->brokerAccountId);
        }

        return $query->first();
    }

    /** @return Collection<int, Asset> */
    private function resolveAssets(?PipelineCycle $cycle, UniverseSelectionService $universe): Collection
    {
        $assetIds = array_map('intval', (array) data_get($cycle?->summary_json, 'asset_ids', []));
        if ($assetIds === []) {
            return $universe->eligibleAssets('4h');
        }

        return Asset::query()->whereIn('id', $assetIds)->orderBy('symbol')->get();
    }

    private function suppressDuplicateCycle(?PipelineCycle $cycle, PipelineCycleService $cycles, EngineJob $job): void
    {
        if ($cycle === null) {
            return;
        }

        foreach (['evaluation', 'result_consumption', 'reconciliation', 'snapshot'] as $step) {
            $cycles->completeStep($cycle, $step, 'Skipped because this logical bar already has an engine job.', ['engine_job_id' => $job->id], 'skipped');
        }
        $cycles->complete($cycle, ['evaluated_assets' => 0, 'duplicate_engine_job_id' => $job->id, 'explanation' => 'The logical bar was already evaluated; no duplicate strategy run was created.']);
    }
}
