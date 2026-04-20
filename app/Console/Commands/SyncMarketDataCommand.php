<?php

namespace App\Console\Commands;

use App\Enums\BrokerType;
use App\Services\Broker\BrokerSyncJobFactory;
use Illuminate\Bus\Dispatcher;
use Illuminate\Console\Command;

class SyncMarketDataCommand extends Command
{
    protected $signature = 'broker:sync-market-data {--broker= : Broker (coinbase|robinhood)} {--credential= : Broker credential ID} {--timeframe=1d : Timeframe to ingest (1d|4h)} {--sync : Run synchronously}';

    protected $description = 'Ingest broker market quotes and candles into local market-data tables.';

    public function handle(Dispatcher $dispatcher, BrokerSyncJobFactory $jobFactory): int
    {
        $broker = BrokerType::tryFrom((string) $this->option('broker')) ?? BrokerType::default();
        $credentialId = $this->option('credential') !== null ? (int) $this->option('credential') : null;
        $timeframe = (string) $this->option('timeframe');

        $job = $jobFactory->marketDataJob($broker, $credentialId, $timeframe);
        $brokerLabel = ucfirst($broker->value);

        if ((bool) $this->option('sync')) {
            $dispatcher->dispatchSync($job);
            $this->info($brokerLabel.' market-data sync executed synchronously.');

            return self::SUCCESS;
        }

        $dispatcher->dispatch($job);
        $this->info($brokerLabel.' market-data sync job queued.');

        return self::SUCCESS;
    }
}
