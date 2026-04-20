<?php

namespace App\Console\Commands;

use App\Enums\BrokerType;
use App\Services\Broker\BrokerSyncJobFactory;
use Illuminate\Bus\Dispatcher;
use Illuminate\Console\Command;

class SyncRobinhoodCommand extends Command
{
    protected $signature = 'broker:sync {--broker= : Broker (coinbase|robinhood)} {--credential= : Broker credential ID} {--timeframe=1d : Market-data timeframe (1d|4h)} {--sync : Run jobs synchronously}';

    protected $aliases = ['broker:sync-robinhood'];

    protected $description = 'Run broker account, asset, market data, position, and order synchronization jobs.';

    public function handle(Dispatcher $dispatcher, BrokerSyncJobFactory $jobFactory): int
    {
        $broker = BrokerType::tryFrom((string) $this->option('broker')) ?? BrokerType::default();

        // Backward-compatible behavior for alias usage.
        if ((string) $this->input->getFirstArgument() === 'broker:sync-robinhood') {
            $broker = BrokerType::ROBINHOOD;
        }

        $credentialId = $this->option('credential') !== null ? (int) $this->option('credential') : null;
        $timeframe = (string) $this->option('timeframe');
        $jobs = $jobFactory->make($broker, $credentialId, $timeframe);

        foreach ($jobs as $job) {
            if ((bool) $this->option('sync')) {
                $dispatcher->dispatchSync($job);
            } else {
                $dispatcher->dispatch($job);
            }
        }

        $brokerLabel = ucfirst($broker->value);
        $this->info((bool) $this->option('sync')
            ? $brokerLabel.' sync jobs executed synchronously.'
            : $brokerLabel.' sync jobs queued.');

        return self::SUCCESS;
    }
}
