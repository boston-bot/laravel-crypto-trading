<?php

namespace App\Jobs;

use App\Models\AssetEvaluation;
use App\Models\EngineResult;
use App\Models\PipelineCycle;
use App\Models\StrategyRun;
use App\Models\TradeDecision;
use App\Services\Operations\ActivityEventService;
use App\Services\Operations\PipelineCycleService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ConsumeEngineResultsJob implements ShouldQueue
{
    use Queueable;

    public function handle(Dispatcher $dispatcher): void
    {
        EngineResult::query()->whereNull('consumed_at')->where('result_kind', 'evaluation')
            ->orderBy('id')->limit(25)->get()
            ->each(fn (EngineResult $result) => $this->consume($result, $dispatcher));
    }

    private function consume(EngineResult $result, Dispatcher $dispatcher): void
    {
        DB::transaction(function () use ($result, $dispatcher): void {
            $locked = EngineResult::query()->whereKey($result->id)->lockForUpdate()->first();
            if ($locked === null || $locked->consumed_at !== null) {
                return;
            }

            $job = $locked->job()->firstOrFail();
            $payload = (array) $job->payload_json;
            $strategyRun = StrategyRun::query()->find((int) ($payload['strategy_run_id'] ?? 0));
            if ($strategyRun === null) {
                $locked->update(['consumed_at' => now()]);

                return;
            }

            $expected = collect((array) ($payload['assets'] ?? []))
                ->filter(fn (mixed $asset): bool => is_array($asset) && (int) ($asset['id'] ?? 0) > 0)
                ->keyBy(fn (array $asset): int => (int) $asset['id']);
            $rawProposals = collect((array) data_get($locked->payload_json, 'proposals', []))
                ->filter(fn (mixed $proposal): bool => is_array($proposal));
            $proposalCounts = $rawProposals->countBy(fn (array $proposal): int => (int) ($proposal['asset_id'] ?? 0));
            $proposals = $rawProposals->keyBy(fn (array $proposal): int => (int) ($proposal['asset_id'] ?? 0));
            $expectedIds = $expected->keys()->map(fn (mixed $id): int => (int) $id)->sort()->values();
            $proposalIds = $proposals->keys()->filter(fn (mixed $id): bool => (int) $id > 0)->map(fn (mixed $id): int => (int) $id)->sort()->values();
            $duplicateProposalIds = $proposalCounts->filter(fn (int $count, mixed $id): bool => (int) $id > 0 && $count > 1)->keys()->values()->all();
            $outputComplete = $expectedIds->all() === $proposalIds->all() && $duplicateProposalIds === [];

            $createdDecisions = 0;
            $approved = 0;
            $evaluationKind = (string) ($payload['evaluation_kind'] ?? 'trading');
            $logicalBarClose = CarbonImmutable::parse((string) ($payload['logical_bar_close'] ?? $job->as_of), 'UTC')->utc();
            $resultFresh = $locked->valid_until === null || $locked->valid_until->isFuture();
            $logicalDeadline = $logicalBarClose->addMinutes((int) config('research.engine.max_signal_age_minutes', 240));
            $logicalFresh = $logicalDeadline->isFuture();

            foreach ($expected as $assetId => $asset) {
                $proposal = $proposals->get((int) $assetId);
                $missing = ! is_array($proposal);
                $proposal = $missing ? [
                    'asset_id' => (int) $assetId,
                    'action' => 'HOLD',
                    'score' => 0,
                    'calibrated_probability' => 0.5,
                    'warnings' => ['missing_engine_proposal'],
                ] : $proposal;
                $action = strtoupper((string) ($proposal['action'] ?? 'HOLD'));
                $warnings = array_values(array_unique(array_map('strval', (array) ($proposal['warnings'] ?? []))));
                if ($missing && ! in_array('missing_engine_proposal', $warnings, true)) {
                    $warnings[] = 'missing_engine_proposal';
                }
                $probability = (float) ($proposal['calibrated_probability'] ?? 0.5);
                $entryThreshold = (float) config('trading.entry.min_probability', 0.52);
                $eligible = ! $missing && ! in_array('missing_point_in_time_candles', $warnings, true);
                $suppressionReason = $this->suppressionReason($evaluationKind, $action, $eligible, $outputComplete, $resultFresh, $logicalFresh);
                $actionable = $suppressionReason === null;
                $explanation = $this->explanation($action, $probability, $entryThreshold, $warnings, $suppressionReason);
                $evaluation = AssetEvaluation::query()->firstOrCreate(
                    ['engine_result_id' => $locked->id, 'asset_id' => (int) $assetId],
                    [
                        'pipeline_cycle_id' => $payload['pipeline_cycle_id'] ?? null,
                        'broker_account_id' => (int) $payload['broker_account_id'],
                        'strategy_run_id' => $strategyRun->id,
                        'strategy_version_id' => $job->strategy_version_id,
                        'universe_version_id' => $job->universe_version_id,
                        'mode' => (string) ($payload['mode'] ?? $strategyRun->mode->value),
                        'evaluation_kind' => $evaluationKind,
                        'logical_bar_close' => $logicalBarClose,
                        'as_of' => $locked->as_of,
                        'eligible' => $eligible,
                        'actionable' => $actionable,
                        'action_suppressed_at' => $actionable ? null : now(),
                        'action_suppression_reason' => $suppressionReason,
                        'action' => $action,
                        'score' => (float) ($proposal['score'] ?? 0),
                        'calibrated_probability' => $probability,
                        'expected_value_bps' => $proposal['expected_value_bps'] ?? null,
                        'primary_explanation' => $explanation,
                        'thresholds_json' => [
                            'entry_probability' => $entryThreshold,
                            'result_valid_until' => $locked->valid_until?->toIso8601String(),
                            'logical_bar_deadline' => $logicalDeadline->toIso8601String(),
                        ],
                        'factor_attribution_json' => (array) ($proposal['factor_attribution'] ?? []),
                        'reason_codes_json' => array_values(array_unique([...$warnings, ...($suppressionReason !== null ? [$suppressionReason] : [])])),
                        'warnings_json' => $warnings,
                        'candle_evidence_json' => [
                            'manifest_hash' => $locked->manifest_hash,
                            'as_of' => $locked->as_of->toIso8601String(),
                            'evidence_cutoff' => $payload['evidence_cutoff'] ?? null,
                        ],
                    ],
                );

                if ($evaluation->wasRecentlyCreated) {
                    app(ActivityEventService::class)->record(
                        'asset_evaluation', $evaluation->id, 'recorded', 'strategy',
                        $action.' '.(string) ($asset['symbol'] ?? ''), $explanation,
                        $action === 'HOLD' ? 'info' : 'warning',
                        (int) $payload['broker_account_id'], (int) $assetId, $payload['pipeline_cycle_id'] ?? null,
                        ['probability' => $probability, 'threshold' => $entryThreshold, 'actionable' => $actionable, 'suppression_reason' => $suppressionReason],
                    );
                }

                if (! $actionable || TradeDecision::query()->where('asset_evaluation_id', $evaluation->id)->exists()) {
                    continue;
                }

                $signal = (array) ($proposal['signal'] ?? []);
                $signal['action'] = $action;
                $signal['score'] = (float) ($proposal['score'] ?? 0);
                $signal['confidence'] = $probability;
                $signal['warnings'] = $warnings;
                $signal['signal_context'] = array_merge((array) ($signal['signal_context'] ?? []), [
                    'engine' => [
                        'job_id' => $job->id,
                        'engine_version' => $locked->engine_version,
                        'schema_version' => $locked->schema_version,
                        'manifest_hash' => $locked->manifest_hash,
                        'expected_value_bps' => $proposal['expected_value_bps'] ?? null,
                        'factor_attribution' => $proposal['factor_attribution'] ?? [],
                        'warnings' => $warnings,
                    ],
                ]);

                $decisionId = $dispatcher->dispatchSync(new CreateTradeDecisionJob(
                    $strategyRun->id, (int) $assetId, (int) $payload['broker_account_id'], $signal, $job->id, $evaluation->id,
                ));
                $createdDecisions++;
                $decision = TradeDecision::query()->find($decisionId);
                if ($decision?->status?->value === 'approved') {
                    $approved++;
                    SubmitBrokerOrderJob::dispatch($decision->id)->afterCommit();
                }
            }

            $strategyRun->update([
                'status' => $outputComplete ? 'completed' : 'failed',
                'completed_at' => now(),
                'summary_json' => array_merge((array) $strategyRun->summary_json, [
                    'engine_job_id' => $job->id,
                    'evaluations_created' => AssetEvaluation::query()->where('engine_result_id', $locked->id)->count(),
                    'decisions_created' => $createdDecisions,
                    'approved_decisions' => $approved,
                    'manifest_hash' => $locked->manifest_hash,
                    'output_complete' => $outputComplete,
                    'unexpected_asset_ids' => $proposalIds->diff($expectedIds)->values()->all(),
                    'missing_asset_ids' => $expectedIds->diff($proposalIds)->values()->all(),
                    'duplicate_asset_ids' => $duplicateProposalIds,
                ]),
            ]);
            $locked->update(['consumed_at' => now()]);

            $cycleId = (string) ($payload['pipeline_cycle_id'] ?? '');
            if ($cycleId === '') {
                return;
            }

            $cycle = PipelineCycle::query()->find($cycleId);
            if ($cycle === null) {
                return;
            }

            $cycles = app(PipelineCycleService::class);
            $cycles->completeStep($cycle, 'evaluation', 'The strategy engine evaluated the pinned universe.', ['evaluations' => $expected->count()]);
            if (! $outputComplete) {
                $cycles->completeStep($cycle, 'result_consumption', 'Engine output did not match the pinned universe.', [
                    'missing_asset_ids' => $expectedIds->diff($proposalIds)->values()->all(),
                    'unexpected_asset_ids' => $proposalIds->diff($expectedIds)->values()->all(),
                    'duplicate_asset_ids' => $duplicateProposalIds,
                ], 'failed');
                foreach (['reconciliation', 'snapshot'] as $step) {
                    $cycles->completeStep($cycle, $step, 'Skipped because engine output validation failed.', status: 'skipped');
                }
                $cycles->complete($cycle, ['evaluated_assets' => $expected->count(), 'explanation' => 'Evaluations were preserved, but incomplete engine output suppressed every decision.']);

                return;
            }

            $cycles->completeStep($cycle, 'result_consumption', 'Engine results were converted into durable evaluations and eligible decisions.', ['decisions' => $createdDecisions]);
            FinalizePipelineCycleJob::dispatch($cycleId)->afterCommit();
        });
    }

    /** @param array<int, string> $warnings */
    private function explanation(string $action, float $probability, float $threshold, array $warnings, ?string $suppressionReason): string
    {
        if (in_array('missing_engine_proposal', $warnings, true)) {
            return 'No action was possible because the engine omitted this asset from its result.';
        }
        if (in_array('missing_point_in_time_candles', $warnings, true)) {
            return 'No action was possible because point-in-time candle history was incomplete.';
        }
        if ($suppressionReason === 'incomplete_engine_output') {
            return 'The evaluation was preserved, but every decision was suppressed because engine output did not match the pinned universe.';
        }
        if ($suppressionReason === 'expired_result') {
            return 'The evaluation was preserved, but its result-processing deadline had elapsed.';
        }
        if ($suppressionReason === 'stale_logical_bar') {
            return 'The evaluation was preserved, but the logical bar was too old to authorize a decision.';
        }
        if ($action === 'HOLD' && $probability < $threshold) {
            return sprintf('No trade was proposed because calibrated probability %.1f%% was below the %.1f%% entry threshold.', $probability * 100, $threshold * 100);
        }
        if ($action === 'HOLD') {
            return 'No trade was proposed because the strategy conditions did not align.';
        }

        return sprintf('%s was proposed with %.1f%% calibrated probability.', $action, $probability * 100);
    }

    private function suppressionReason(string $evaluationKind, string $action, bool $eligible, bool $outputComplete, bool $resultFresh, bool $logicalFresh): ?string
    {
        if (! $outputComplete) {
            return 'incomplete_engine_output';
        }
        if (! $eligible) {
            return 'ineligible_evidence';
        }
        if ($evaluationKind !== 'trading') {
            return 'diagnostic_evaluation';
        }
        if ($action === 'HOLD') {
            return 'hold';
        }
        if (! $resultFresh) {
            return 'expired_result';
        }
        if (! $logicalFresh) {
            return 'stale_logical_bar';
        }

        return null;
    }
}
