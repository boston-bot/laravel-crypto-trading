<?php

namespace App\Jobs;

use App\Models\PipelineCycle;
use App\Services\Operations\PipelineCycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FinalizePipelineCycleJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $cycleId) {}

    public function handle(PipelineCycleService $cycles): void
    {
        $cycle = PipelineCycle::query()->with('account')->find($this->cycleId);
        if ($cycle === null || in_array($cycle->status, ['completed', 'failed'], true)) {
            return;
        }

        if (! $cycle->steps()->where('step_key', 'result_consumption')->where('status', 'completed')->exists()) {
            return;
        }

        if ($cycle->evaluation_kind === 'diagnostic') {
            foreach (['reconciliation', 'snapshot'] as $step) {
                if (! $cycle->steps()->where('step_key', $step)->whereIn('status', ['completed', 'skipped'])->exists()) {
                    $cycles->completeStep($cycle, $step, 'Diagnostic evaluations do not reconcile or value a paper portfolio.', status: 'skipped');
                }
            }
        } else {
            if (! $cycle->steps()->where('step_key', 'reconciliation')->whereIn('status', ['completed', 'skipped'])->exists()) {
                $cycles->beginStep($cycle, 'reconciliation');
                app()->call([new ReconcileBrokerFillJob, 'handle']);
                $cycles->completeStep($cycle, 'reconciliation');
            }
            if (! $cycle->steps()->where('step_key', 'snapshot')->whereIn('status', ['completed', 'skipped'])->exists()) {
                $cycles->beginStep($cycle, 'snapshot');
                app()->call([new SnapshotPaperPortfolioJob($cycle->account->broker->value, $cycle->broker_account_id), 'handle']);
                $cycles->completeStep($cycle, 'snapshot');
            }
        }

        $evaluations = $cycle->evaluations()->get();
        $holds = $evaluations->where('action', 'HOLD')->count();
        $actionable = $evaluations->where('actionable', true)->count();
        $cycles->complete($cycle, [
            'evaluated_assets' => $evaluations->count(),
            'holds' => $holds,
            'proposals' => $evaluations->count() - $holds,
            'actionable' => $actionable,
            'explanation' => $evaluations->isEmpty()
                ? 'The engine returned no asset evaluations.'
                : ($holds === $evaluations->count() ? 'No trade was placed because every evaluated asset resulted in HOLD.' : 'The cycle produced one or more actionable proposals.'),
        ]);
    }
}
