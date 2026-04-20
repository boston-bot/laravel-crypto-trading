<?php

namespace App\Console\Commands;

use App\Enums\BrokerType;
use App\Jobs\EvaluateSignalsJob;
use App\Models\BrokerAccount;
use Illuminate\Bus\Dispatcher;
use Illuminate\Console\Command;

class EvaluateStrategiesCommand extends Command
{
    protected $signature = 'strategy:evaluate {--mode= : paper|live override} {--broker= : Broker (coinbase|robinhood)} {--sync : Run immediately in-process}';

    protected $description = 'Run the configured strategy evaluation and produce trade decisions.';

    public function handle(Dispatcher $dispatcher): int
    {
        $broker = BrokerType::tryFrom((string) $this->option('broker'));
        $brokerAccountId = null;
        if ($broker !== null) {
            $brokerAccountId = BrokerAccount::query()
                ->where('broker', $broker->value)
                ->value('id');
        }

        $job = new EvaluateSignalsJob(
            brokerAccountId: is_numeric($brokerAccountId) ? (int) $brokerAccountId : null,
            mode: $this->option('mode') ?: null,
        );

        if ((bool) $this->option('sync')) {
            $dispatcher->dispatchSync($job);
            $this->info('Strategy evaluation executed synchronously.');

            return self::SUCCESS;
        }

        $dispatcher->dispatch($job);
        $this->info('Strategy evaluation queued.');

        return self::SUCCESS;
    }
}
