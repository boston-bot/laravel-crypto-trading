<?php

namespace App\Jobs;

use App\Enums\BrokerType;
use App\Models\BrokerAccount;
use App\Models\StrategyRun;
use App\Services\Strategy\SkillCatalogService;
use App\Services\Strategy\UniverseSelectionService;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EvaluateSignalsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $brokerAccountId = null,
        public readonly ?string $mode = null,
    ) {
    }

    public function handle(
        Dispatcher $dispatcher,
        SkillCatalogService $skillCatalogService,
        UniverseSelectionService $universeSelectionService,
    ): void
    {
        $brokerAccount = $this->resolveBrokerAccount();
        if ($brokerAccount === null) {
            return;
        }

        $mode = $this->mode ?? (string) config('broker.mode', 'paper');
        $strategyRun = StrategyRun::query()->create([
            'strategy_name' => (string) config('trading.strategy_name'),
            'mode' => $mode,
            'started_at' => now(),
            'account_equity' => $brokerAccount->equity,
            'status' => 'running',
        ]);

        $assets = $universeSelectionService->eligibleAssets();

        $decisionCount = 0;
        $blockedCount = 0;
        $approvedCount = 0;

        foreach ($assets as $asset) {
            $dispatcher->dispatchSync(new CreateTradeDecisionJob($strategyRun->id, $asset->id, $brokerAccount->id));

            ++$decisionCount;
            $decision = $strategyRun->tradeDecisions()
                ->where('asset_id', $asset->id)
                ->latest('id')
                ->first();
            if ($decision === null) {
                continue;
            }

            if ($decision->status?->value === 'blocked_by_policy') {
                ++$blockedCount;
            }

            if ($decision->status?->value === 'approved') {
                ++$approvedCount;
                if ($mode === 'paper') {
                    $dispatcher->dispatchSync(new SubmitBrokerOrderJob($decision->id));
                } else {
                    SubmitBrokerOrderJob::dispatch($decision->id);
                }
            }
        }

        $strategyRun->update([
            'completed_at' => now(),
            'status' => 'completed',
            'summary_json' => [
                'assets_evaluated' => $assets->count(),
                'decisions_created' => $decisionCount,
                'approved_decisions' => $approvedCount,
                'blocked_decisions' => $blockedCount,
            ],
            'skill_outputs_json' => [
                'skills' => $skillCatalogService->listLocalSkills(),
            ],
        ]);
    }

    private function resolveBrokerAccount(): ?BrokerAccount
    {
        $broker = BrokerType::default();
        $query = BrokerAccount::query()
            ->where('broker', $broker->value);

        if ($this->brokerAccountId !== null) {
            $query->whereKey($this->brokerAccountId);
        }

        return $query->first();
    }
}
