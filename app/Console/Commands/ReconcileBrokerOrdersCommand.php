<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileBrokerFillJob;
use Illuminate\Bus\Dispatcher;
use Illuminate\Console\Command;

class ReconcileBrokerOrdersCommand extends Command
{
    protected $signature = 'broker:reconcile-orders {orderId? : Optional broker order ID} {--sync : Run immediately in-process}';

    protected $description = 'Reconcile non-terminal broker order states.';

    public function handle(Dispatcher $dispatcher): int
    {
        $orderId = $this->argument('orderId') !== null ? (int) $this->argument('orderId') : null;
        $job = new ReconcileBrokerFillJob($orderId);

        if ((bool) $this->option('sync')) {
            $dispatcher->dispatchSync($job);
            $this->info('Order reconciliation executed synchronously.');

            return self::SUCCESS;
        }

        $dispatcher->dispatch($job);
        $this->info('Order reconciliation queued.');

        return self::SUCCESS;
    }
}
