<?php

namespace App\Jobs;

use App\Models\PipelineCycle;
use App\Services\MarketData\CanonicalMarketEvidenceService;
use App\Services\Operations\PipelineCycleService;
use App\Services\Strategy\UniverseSelectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunPipelineCycleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public array $backoff = [15, 60, 180];

    public function __construct(public readonly string $cycleId) {}

    public function handle(PipelineCycleService $cycles, UniverseSelectionService $universe, CanonicalMarketEvidenceService $evidence): void
    {
        $cycle = PipelineCycle::query()->with(['account', 'paperSession'])->findOrFail($this->cycleId);
        if (in_array($cycle->status, ['completed', 'failed'], true)) {
            return;
        }

        try {
            if ($cycle->logical_bar_close === null) {
                if (! $this->prepareUnresolvedCycle($cycle, $cycles, $universe, $evidence)) {
                    return;
                }
                $cycle->refresh();
            }
            $cycle->loadMissing('paperSession');
            if ($cycle->mode === 'paper' && $cycle->paperSession?->evidence_eligible
                && ($cycle->strategy_version_id !== $cycle->paperSession->strategy_version_id
                    || $cycle->universe_version_id !== $cycle->paperSession->universe_version_id)) {
                $cycles->fail($cycle, 'The cycle versions do not match the evidence-eligible paper session pins.');

                return;
            }

            $evaluationStep = $cycle->steps()->where('step_key', 'evaluation')->firstOrFail();
            if (in_array($evaluationStep->status, ['completed', 'skipped'], true)) {
                if ($cycle->steps()->where('step_key', 'result_consumption')->where('status', 'completed')->exists()) {
                    app()->call([new FinalizePipelineCycleJob($cycle->id), 'handle']);
                }

                return;
            }
            if ($evaluationStep->status === 'waiting_engine') {
                $engineJob = $evaluationStep->engineJob()->with('result')->first();
                if ($engineJob?->result !== null && $engineJob->result->consumed_at === null) {
                    app()->call([new ConsumeEngineResultsJob, 'handle']);

                    return;
                }
                if ($engineJob !== null && in_array($engineJob->status, ['failed', 'expired'], true)) {
                    $cycles->fail($cycle, 'The engine job became terminal without a consumable result.');
                }

                return;
            }

            $cycles->beginStep($cycle, 'evaluation', ['logical_bar_close' => $cycle->logical_bar_close?->toIso8601String()]);
            app()->call([new EvaluateSignalsJob(
                $cycle->broker_account_id,
                $cycle->mode,
                $cycle->id,
                $cycle->evaluation_kind,
                $cycle->logical_bar_close?->toIso8601String(),
            ), 'handle']);

            $cycle->refresh();
            if ($cycle->status === 'waiting_engine' || in_array($cycle->status, ['completed', 'failed'], true)) {
                return;
            }

            if ($cycle->steps()->where('step_key', 'result_consumption')->where('status', 'completed')->exists()) {
                app()->call([new FinalizePipelineCycleJob($cycle->id), 'handle']);
            }
        } catch (Throwable $exception) {
            $cycles->fail($cycle->fresh(), $exception->getMessage());
            throw $exception;
        }
    }

    private function prepareUnresolvedCycle(
        PipelineCycle $cycle,
        PipelineCycleService $cycles,
        UniverseSelectionService $universe,
        CanonicalMarketEvidenceService $evidence,
    ): bool {
        $cycles->beginStep($cycle, 'data_refresh');
        app()->call([new SyncCoinbaseMarketDataJob(timeframe: '1h'), 'handle']);
        $cycles->completeStep($cycle, 'data_refresh', 'Canonical Coinbase quotes and hourly candles were refreshed.');

        $cycles->beginStep($cycle, 'eligibility');
        $session = $cycle->account->paperSessions()->where('status', 'active')->latest('id')->first();
        if ($cycle->mode === 'paper' && $session === null) {
            $cycles->completeStep($cycle, 'eligibility', 'No active paper session exists.', status: 'skipped');
            foreach (['evaluation', 'result_consumption', 'reconciliation', 'snapshot'] as $step) {
                $cycles->completeStep($cycle, $step, 'Skipped until a paper session is started.', status: 'skipped');
            }
            $cycles->complete($cycle, ['evaluated_assets' => 0, 'explanation' => 'No paper action was possible because no paper session is active.']);

            return false;
        }
        if ($session?->evidence_eligible && ($session->strategy_version_id === null || $session->universe_version_id === null)) {
            $cycles->fail($cycle, 'The evidence-eligible paper session is missing immutable version pins.');

            return false;
        }

        $assets = $universe->eligibleAssets('4h');
        $bar = $evidence->latestCommonEligibleBar($assets, now()->utc());
        if ($bar === null) {
            $cycles->completeStep($cycle, 'eligibility', 'No common final point-in-time four-hour bar is available.', status: 'skipped');
            foreach (['evaluation', 'result_consumption', 'reconciliation', 'snapshot'] as $step) {
                $cycles->completeStep($cycle, $step, 'Skipped because the exact universe has no common eligible bar.', status: 'skipped');
            }
            $cycles->complete($cycle, ['evaluated_assets' => 0, 'explanation' => 'No evaluation ran because canonical universe candle coverage was incomplete.']);

            return false;
        }

        $duplicate = PipelineCycle::query()
            ->where('id', '!=', $cycle->id)
            ->where('broker_account_id', $cycle->broker_account_id)
            ->where('mode', $cycle->mode)
            ->where('evaluation_kind', $cycle->evaluation_kind)
            ->where('logical_bar_close', $bar->logicalBarClose)
            ->when($session?->strategy_version_id === null, fn ($query) => $query->whereNull('strategy_version_id'), fn ($query) => $query->where('strategy_version_id', $session->strategy_version_id))
            ->when($session?->universe_version_id === null, fn ($query) => $query->whereNull('universe_version_id'), fn ($query) => $query->where('universe_version_id', $session->universe_version_id))
            ->first();
        if ($duplicate !== null) {
            $cycles->completeStep($cycle, 'eligibility', 'This logical bar already has a cycle.', status: 'skipped');
            foreach (['evaluation', 'result_consumption', 'reconciliation', 'snapshot'] as $step) {
                $cycles->completeStep($cycle, $step, 'Skipped as a duplicate logical bar.', status: 'skipped');
            }
            $cycles->complete($cycle, ['evaluated_assets' => 0, 'duplicate_cycle_id' => $duplicate->id, 'explanation' => 'The logical bar was already evaluated.']);

            return false;
        }

        $cycle->update([
            'paper_session_id' => $session?->id,
            'strategy_version_id' => $session?->strategy_version_id,
            'universe_version_id' => $session?->universe_version_id,
            'as_of' => $bar->logicalBarClose,
            'logical_bar_close' => $bar->logicalBarClose,
            'evidence_cutoff' => $bar->evidenceCutoff,
            'summary_json' => array_merge((array) $cycle->summary_json, ['asset_ids' => $bar->assetIds, 'candle_ids_by_asset' => $bar->candleIdsByAsset]),
        ]);
        $cycles->completeStep($cycle, 'eligibility', 'A common final point-in-time four-hour bar is eligible.', ['logical_bar_close' => $bar->logicalBarClose->toIso8601String(), 'asset_ids' => $bar->assetIds]);

        return true;
    }
}
