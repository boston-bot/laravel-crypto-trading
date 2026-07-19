<?php

use App\Enums\BrokerType;
use App\Jobs\ConsumeBacktestResultsJob;
use App\Jobs\ConsumeEngineResultsJob;
use App\Jobs\EnqueueSentimentRefreshJob;
use App\Jobs\PipelineHeartbeatJob;
use App\Jobs\ReconcileBrokerFillJob;
use App\Jobs\SnapshotPaperPortfolioJob;
use App\Jobs\SyncCoinbaseAccountJob;
use App\Jobs\SyncCoinbaseAssetsJob;
use App\Jobs\SyncCoinbaseMarketDataJob;
use App\Jobs\SyncCoinbaseOrdersJob;
use App\Jobs\SyncCoinbasePositionsJob;
use App\Jobs\SyncFearGreedSentimentJob;
use App\Jobs\SyncFeeSchedulesJob;
use App\Jobs\SyncRobinhoodAccountJob;
use App\Jobs\SyncRobinhoodAssetsJob;
use App\Jobs\SyncRobinhoodMarketDataJob;
use App\Jobs\SyncRobinhoodOrdersJob;
use App\Jobs\SyncRobinhoodPositionsJob;
use App\Services\Research\EngineJobService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$defaultBroker = BrokerType::default();

if ($defaultBroker === BrokerType::COINBASE) {
    Schedule::job(new SyncCoinbaseAccountJob)->everyFiveMinutes();
    Schedule::job(new SyncCoinbaseAssetsJob)->everyTenMinutes();
    Schedule::job(new SyncCoinbaseMarketDataJob(timeframe: '1d'))->everyTenMinutes();
    Schedule::job(new SyncCoinbaseMarketDataJob(timeframe: '4h'))->hourly();
    Schedule::job(new SyncCoinbasePositionsJob)->everyFiveMinutes();
    Schedule::job(new SyncCoinbaseOrdersJob)->everyFiveMinutes();
    Schedule::job(new SyncFeeSchedulesJob)->hourly();
} else {
    Schedule::job(new SyncRobinhoodAccountJob)->everyFiveMinutes();
    Schedule::job(new SyncRobinhoodAssetsJob)->everyTenMinutes();
    Schedule::job(new SyncRobinhoodMarketDataJob(timeframe: '1d'))->everyTenMinutes();
    Schedule::job(new SyncRobinhoodMarketDataJob(timeframe: '4h'))->hourly();
    Schedule::job(new SyncRobinhoodPositionsJob)->everyFiveMinutes();
    Schedule::job(new SyncRobinhoodOrdersJob)->everyFiveMinutes();
}

Schedule::job(new PipelineHeartbeatJob)->everyMinute()->withoutOverlapping();
Schedule::job(new ConsumeEngineResultsJob)->everyMinute();
Schedule::job(new ConsumeBacktestResultsJob)->everyMinute();
if (in_array((string) config('research.engine.driver', 'legacy'), ['database', 'python'], true)) {
    Schedule::job(new EnqueueSentimentRefreshJob)->dailyAt('00:10');
} else {
    Schedule::job(new SyncFearGreedSentimentJob)->dailyAt('00:10');
}
Schedule::call(function (): void {
    app(EngineJobService::class)->expireStaleLeases();
    app(EngineJobService::class)->expireOverdueJobs();
})->everyMinute();
Schedule::command('research:maintain-market-data')->dailyAt('00:20');

Schedule::job(new ReconcileBrokerFillJob)->everyMinute();
Schedule::job(new SnapshotPaperPortfolioJob($defaultBroker->value))->everyMinute();
