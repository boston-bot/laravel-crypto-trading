<?php

namespace App\Services\Operations;

use App\Data\MarketData\CommonMarketBar;
use App\Jobs\RunPipelineCycleJob;
use App\Models\BrokerAccount;
use App\Models\PaperSession;
use App\Models\PipelineCycle;
use App\Models\PipelineCycleStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class PipelineCycleService
{
    private const STEPS = ['data_refresh', 'eligibility', 'evaluation', 'result_consumption', 'reconciliation', 'snapshot'];

    private const ACTIVE_STATUSES = ['queued', 'running', 'waiting_engine'];

    private const TERMINAL_STEP_STATUSES = ['completed', 'skipped', 'failed'];

    public function __construct(private readonly ActivityEventService $activity) {}

    public function request(BrokerAccount $account, string $trigger = 'automatic', string $mode = 'paper', ?string $idempotencyKey = null): PipelineCycle
    {
        try {
            $created = false;
            $cycle = DB::transaction(function () use ($account, $trigger, $mode, $idempotencyKey, &$created): PipelineCycle {
                $active = PipelineCycle::query()
                    ->where('broker_account_id', $account->id)
                    ->where('mode', $mode)
                    ->whereIn('status', self::ACTIVE_STATUSES)
                    ->lockForUpdate()
                    ->latest('created_at')
                    ->first();

                if ($active !== null) {
                    $this->activity->record('pipeline_cycle', $active->id, 'coalesced', 'system', 'Cycle request coalesced', 'Another cycle was already active, so this request reused it.', 'info', $account->id, cycleId: $active->id, detail: ['trigger' => $trigger]);

                    return $active;
                }

                $asOf = now()->utc()->startOfMinute();
                $key = $idempotencyKey ?: hash('sha256', implode('|', [$account->id, $mode, $trigger, $asOf->format('Y-m-d\TH:i\Z')]));
                $cycle = PipelineCycle::query()->firstOrCreate(
                    ['cycle_key' => $key],
                    [
                        'id' => (string) Str::uuid(),
                        'broker_account_id' => $account->id,
                        'paper_session_id' => $account->paperSessions()->where('status', 'active')->value('id'),
                        'mode' => $mode,
                        'trigger' => $trigger,
                        'evaluation_kind' => $trigger === 'diagnostic' ? 'diagnostic' : 'trading',
                        'status' => 'queued',
                        'as_of' => $asOf,
                        'evidence_cutoff' => $asOf,
                        'heartbeat_at' => now(),
                        'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                        'summary_json' => ['requested_trigger' => $trigger],
                    ],
                );
                $created = $cycle->wasRecentlyCreated;

                if ($created) {
                    $this->createSteps($cycle);
                    $this->activity->record('pipeline_cycle', $cycle->id, 'queued', 'system', 'Pipeline cycle queued', 'A new paper pipeline cycle is waiting for the queue worker.', 'info', $account->id, cycleId: $cycle->id, detail: ['trigger' => $trigger]);
                }

                return $cycle;
            });
        } catch (QueryException) {
            $cycle = PipelineCycle::query()->where('cycle_key', $idempotencyKey)->first()
                ?? PipelineCycle::query()->where('broker_account_id', $account->id)->where('mode', $mode)->whereIn('status', self::ACTIVE_STATUSES)->latest()->firstOrFail();
            $created = false;
        }

        if ($created) {
            RunPipelineCycleJob::dispatch($cycle->id)->afterCommit();
        }

        return $cycle->fresh('steps');
    }

    public function requestForBar(
        BrokerAccount $account,
        PaperSession $session,
        CommonMarketBar $bar,
        string $trigger = 'automatic',
        string $mode = 'paper',
        string $evaluationKind = 'trading',
    ): PipelineCycle {
        if ($session->evidence_eligible && ($session->strategy_version_id === null || $session->universe_version_id === null)) {
            throw new LogicException('Evidence-eligible paper cycles require immutable strategy and universe pins.');
        }
        $strategyVersionId = $session->strategy_version_id !== null ? (int) $session->strategy_version_id : null;
        $universeVersionId = $session->universe_version_id !== null ? (int) $session->universe_version_id : null;
        $cycleKey = hash('sha256', implode('|', [
            $account->id,
            $mode,
            $evaluationKind,
            $strategyVersionId ?? 'legacy',
            $universeVersionId ?? 'default',
            $bar->logicalBarClose->format('Y-m-d\TH:i:s\Z'),
        ]));
        $shouldDispatch = false;

        try {
            $cycle = DB::transaction(function () use ($account, $session, $bar, $trigger, $mode, $evaluationKind, $strategyVersionId, $universeVersionId, $cycleKey, &$shouldDispatch): PipelineCycle {
                $cycle = $this->logicalIdentityQuery($account->id, $mode, $evaluationKind, $strategyVersionId, $universeVersionId, $bar->logicalBarClose)
                    ->lockForUpdate()
                    ->first();

                if ($cycle === null) {
                    $cycle = PipelineCycle::query()->create([
                        'id' => (string) Str::uuid(),
                        'broker_account_id' => $account->id,
                        'paper_session_id' => $session->id,
                        'strategy_version_id' => $strategyVersionId,
                        'universe_version_id' => $universeVersionId,
                        'mode' => $mode,
                        'trigger' => $trigger,
                        'evaluation_kind' => $evaluationKind,
                        'status' => 'queued',
                        'cycle_key' => $cycleKey,
                        'as_of' => $bar->logicalBarClose,
                        'logical_bar_close' => $bar->logicalBarClose,
                        'evidence_cutoff' => $bar->evidenceCutoff,
                        'heartbeat_at' => now(),
                        'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                        'summary_json' => [
                            'requested_trigger' => $trigger,
                            'asset_ids' => $bar->assetIds,
                            'candle_ids_by_asset' => $bar->candleIdsByAsset,
                        ],
                    ]);
                    $this->createSteps($cycle, $bar);
                    $this->activity->record('pipeline_cycle', $cycle->id, 'queued', 'system', 'Logical-bar cycle queued', 'A new common four-hour bar is ready for strategy evaluation.', 'info', $account->id, cycleId: $cycle->id, detail: ['logical_bar_close' => $bar->logicalBarClose->toIso8601String()]);
                    $shouldDispatch = true;

                    return $cycle;
                }

                if (in_array($cycle->status, ['completed', 'failed'], true)) {
                    $this->activity->record('pipeline_cycle', $cycle->id, 'duplicate_suppressed', 'system', 'Duplicate logical bar suppressed', 'This logical bar already has a terminal cycle.', 'info', $account->id, cycleId: $cycle->id);

                    return $cycle;
                }

                if ($cycle->lease_expires_at === null || $cycle->lease_expires_at->isPast()) {
                    $cycle->update([
                        'heartbeat_at' => now(),
                        'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                    ]);
                    $shouldDispatch = true;
                }

                return $cycle;
            });
        } catch (QueryException) {
            $cycle = $this->logicalIdentityQuery($account->id, $mode, $evaluationKind, $strategyVersionId, $universeVersionId, $bar->logicalBarClose)->firstOrFail();
            $shouldDispatch = false;
        }

        if ($shouldDispatch) {
            RunPipelineCycleJob::dispatch($cycle->id)->afterCommit();
        }

        return $cycle->fresh('steps');
    }

    /** @param array<string, mixed> $context */
    public function beginStep(PipelineCycle $cycle, string $step, array $context = []): void
    {
        DB::transaction(function () use ($cycle, $step, $context): void {
            $lockedCycle = PipelineCycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            $lockedStep = PipelineCycleStep::query()->where('pipeline_cycle_id', $cycle->id)->where('step_key', $step)->lockForUpdate()->firstOrFail();
            if (in_array($lockedStep->status, self::TERMINAL_STEP_STATUSES, true)) {
                if ($lockedStep->status === 'completed' || $lockedStep->status === 'skipped') {
                    return;
                }

                throw new LogicException("Cannot restart failed pipeline step [{$step}].");
            }

            $lockedCycle->update(['status' => 'running', 'current_step' => $step, 'started_at' => $lockedCycle->started_at ?? now(), 'heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds($this->leaseSeconds())]);
            $lockedStep->update(['status' => 'running', 'started_at' => $lockedStep->started_at ?? now(), 'heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds($this->leaseSeconds()), 'context_json' => array_merge((array) $lockedStep->context_json, $context)]);
        });
    }

    /** @param array<string, mixed> $context */
    public function completeStep(PipelineCycle $cycle, string $step, ?string $reason = null, array $context = [], string $status = 'completed'): void
    {
        if (! in_array($status, self::TERMINAL_STEP_STATUSES, true)) {
            throw new LogicException("Invalid terminal step status [{$status}].");
        }

        DB::transaction(function () use ($cycle, $step, $reason, $context, $status): void {
            $lockedStep = PipelineCycleStep::query()->where('pipeline_cycle_id', $cycle->id)->where('step_key', $step)->lockForUpdate()->firstOrFail();
            if (in_array($lockedStep->status, self::TERMINAL_STEP_STATUSES, true)) {
                if ($lockedStep->status === $status) {
                    return;
                }

                throw new LogicException("Pipeline step [{$step}] is already terminal as [{$lockedStep->status}].");
            }

            $lockedStep->update(['status' => $status, 'reason' => $reason, 'context_json' => array_merge((array) $lockedStep->context_json, $context), 'completed_at' => now(), 'lease_expires_at' => null]);
            PipelineCycle::query()->whereKey($cycle->id)->update(['heartbeat_at' => now()]);
        });
    }

    public function waitForEngine(PipelineCycle $cycle, string $engineJobId): void
    {
        DB::transaction(function () use ($cycle, $engineJobId): void {
            PipelineCycle::query()->whereKey($cycle->id)->update(['status' => 'waiting_engine', 'current_step' => 'evaluation', 'heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds($this->leaseSeconds())]);
            PipelineCycleStep::query()->where('pipeline_cycle_id', $cycle->id)->where('step_key', 'evaluation')->update(['status' => 'waiting_engine', 'engine_job_id' => $engineJobId, 'heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds($this->leaseSeconds())]);
        });
    }

    /** @param array<string, mixed> $summary */
    public function complete(PipelineCycle $cycle, array $summary): void
    {
        $completed = DB::transaction(function () use ($cycle, $summary): bool {
            $locked = PipelineCycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['completed', 'failed'], true)) {
                return $locked->status === 'completed';
            }

            $steps = PipelineCycleStep::query()->where('pipeline_cycle_id', $cycle->id)->orderBy('position')->lockForUpdate()->get();
            $active = $steps->whereIn('status', self::ACTIVE_STATUSES);
            if ($active->isNotEmpty()) {
                PipelineCycleStep::query()->whereIn('id', $active->pluck('id'))->update([
                    'status' => 'failed',
                    'reason' => 'parent_terminal_state_violation',
                    'last_error' => 'Cycle finalization found a nonterminal child step.',
                    'completed_at' => now(),
                    'lease_expires_at' => null,
                ]);
                $locked->update(['status' => 'failed', 'current_step' => null, 'last_error' => 'Cycle finalization found nonterminal child steps.', 'completed_at' => now(), 'lease_expires_at' => null]);

                return false;
            }

            if ($steps->contains('status', 'failed')) {
                $locked->update(['status' => 'failed', 'current_step' => null, 'last_error' => 'One or more pipeline steps failed.', 'completed_at' => now(), 'lease_expires_at' => null]);

                return false;
            }

            $locked->update(['status' => 'completed', 'current_step' => null, 'completed_at' => now(), 'lease_expires_at' => null, 'summary_json' => array_merge((array) $locked->summary_json, $summary)]);

            return true;
        });

        if ($completed) {
            $this->activity->record('pipeline_cycle', $cycle->id, 'completed', 'system', 'Pipeline cycle completed', (string) ($summary['explanation'] ?? 'The pipeline cycle completed.'), 'success', $cycle->broker_account_id, cycleId: $cycle->id, detail: $summary);
        } else {
            $this->activity->record('pipeline_cycle', $cycle->id, 'failed', 'system', 'Pipeline cycle failed', 'The cycle could not satisfy its terminal-state invariants.', 'error', $cycle->broker_account_id, cycleId: $cycle->id);
        }
    }

    public function fail(PipelineCycle $cycle, string $message): void
    {
        DB::transaction(function () use ($cycle, $message): void {
            $locked = PipelineCycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['completed', 'failed'], true)) {
                return;
            }

            $steps = PipelineCycleStep::query()->where('pipeline_cycle_id', $cycle->id)->whereIn('status', self::ACTIVE_STATUSES)->lockForUpdate()->get();
            foreach ($steps as $step) {
                $isCurrent = $step->step_key === $locked->current_step;
                $step->update([
                    'status' => $isCurrent ? 'failed' : 'skipped',
                    'reason' => $isCurrent ? 'step_failure' : 'upstream_failure',
                    'last_error' => $isCurrent ? $message : null,
                    'completed_at' => now(),
                    'lease_expires_at' => null,
                ]);
            }
            $locked->update(['status' => 'failed', 'current_step' => null, 'last_error' => $message, 'completed_at' => now(), 'lease_expires_at' => null]);
        });

        $this->activity->record('pipeline_cycle', $cycle->id, 'failed', 'system', 'Pipeline cycle failed', $message, 'error', $cycle->broker_account_id, cycleId: $cycle->id);
    }

    private function createSteps(PipelineCycle $cycle, ?CommonMarketBar $bar = null): void
    {
        foreach (self::STEPS as $position => $step) {
            $prepared = $bar !== null && in_array($step, ['data_refresh', 'eligibility'], true);
            $context = $prepared ? [
                'logical_bar_close' => $bar->logicalBarClose->toIso8601String(),
                'evidence_cutoff' => $bar->evidenceCutoff->toIso8601String(),
                'asset_ids' => $bar->assetIds,
                'candle_ids_by_asset' => $bar->candleIdsByAsset,
            ] : null;
            $cycle->steps()->create([
                'step_key' => $step,
                'position' => $position + 1,
                'status' => $prepared ? 'completed' : 'queued',
                'started_at' => $prepared ? now() : null,
                'completed_at' => $prepared ? now() : null,
                'reason' => $prepared ? ($step === 'data_refresh' ? 'Canonical market evidence was refreshed.' : 'A common universe bar was eligible.') : null,
                'context_json' => $context,
            ]);
        }
    }

    private function logicalIdentityQuery(int $accountId, string $mode, string $evaluationKind, ?int $strategyVersionId, ?int $universeVersionId, mixed $logicalBarClose): Builder
    {
        return PipelineCycle::query()
            ->where('broker_account_id', $accountId)
            ->where('mode', $mode)
            ->where('evaluation_kind', $evaluationKind)
            ->when($strategyVersionId === null, fn (Builder $query): Builder => $query->whereNull('strategy_version_id'), fn (Builder $query): Builder => $query->where('strategy_version_id', $strategyVersionId))
            ->when($universeVersionId === null, fn (Builder $query): Builder => $query->whereNull('universe_version_id'), fn (Builder $query): Builder => $query->where('universe_version_id', $universeVersionId))
            ->where('logical_bar_close', $logicalBarClose);
    }

    private function leaseSeconds(): int
    {
        return (int) config('operations.cycle_lease_seconds', 300);
    }
}
