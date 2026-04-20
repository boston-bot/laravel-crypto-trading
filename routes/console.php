<?php

use App\Enums\BrokerType;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$defaultBroker = BrokerType::default();

if ($defaultBroker === BrokerType::COINBASE) {
    Schedule::job(new \App\Jobs\SyncCoinbaseAccountJob())->everyFiveMinutes();
    Schedule::job(new \App\Jobs\SyncCoinbaseAssetsJob())->everyTenMinutes();
    Schedule::job(new \App\Jobs\SyncCoinbaseMarketDataJob(timeframe: '1d'))->everyTenMinutes();
    Schedule::job(new \App\Jobs\SyncCoinbaseMarketDataJob(timeframe: '4h'))->hourly();
    Schedule::job(new \App\Jobs\SyncCoinbasePositionsJob())->everyFiveMinutes();
    Schedule::job(new \App\Jobs\SyncCoinbaseOrdersJob())->everyFiveMinutes();
} else {
    Schedule::job(new \App\Jobs\SyncRobinhoodAccountJob())->everyFiveMinutes();
    Schedule::job(new \App\Jobs\SyncRobinhoodAssetsJob())->everyTenMinutes();
    Schedule::job(new \App\Jobs\SyncRobinhoodMarketDataJob(timeframe: '1d'))->everyTenMinutes();
    Schedule::job(new \App\Jobs\SyncRobinhoodMarketDataJob(timeframe: '4h'))->hourly();
    Schedule::job(new \App\Jobs\SyncRobinhoodPositionsJob())->everyFiveMinutes();
    Schedule::job(new \App\Jobs\SyncRobinhoodOrdersJob())->everyFiveMinutes();
}

Schedule::job(new \App\Jobs\EvaluateSignalsJob())->dailyAt('09:00');
Schedule::job(new \App\Jobs\ReconcileBrokerFillJob())->everyMinute();
