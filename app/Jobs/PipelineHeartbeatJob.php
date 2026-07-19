<?php

namespace App\Jobs;

use App\Enums\BrokerType;
use App\Models\BrokerAccount;
use App\Services\MarketData\CanonicalMarketEvidenceService;
use App\Services\Operations\PipelineCycleService;
use App\Services\PaperTrading\PaperSessionService;
use App\Services\Strategy\UniverseSelectionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PipelineHeartbeatJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 55;

    public function handle(
        PipelineCycleService $cycles,
        PaperSessionService $sessions,
        UniverseSelectionService $universe,
        CanonicalMarketEvidenceService $evidence,
    ): void {
        $account = BrokerAccount::query()->where('broker', BrokerType::default()->value)->latest('snapshot_at')->first();
        if ($account === null) {
            return;
        }

        app()->call([new SyncCoinbaseMarketDataJob(timeframe: '1h'), 'handle']);

        $session = $sessions->activeFor($account);
        if ($session === null) {
            return;
        }

        $assets = $universe->eligibleAssets('4h');
        $bar = $evidence->latestCommonEligibleBar($assets, now()->utc());
        if ($bar === null) {
            return;
        }

        $cycles->requestForBar($account, $session, $bar, 'automatic', (string) config('broker.mode', 'paper'));
    }

    public function uniqueId(): string
    {
        return BrokerType::default()->value.'|'.now()->utc()->format('Y-m-d-H-i');
    }
}
