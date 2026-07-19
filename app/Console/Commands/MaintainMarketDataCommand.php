<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainMarketDataCommand extends Command
{
    protected $signature = 'research:maintain-market-data';

    protected $description = 'Create upcoming PostgreSQL raw-event partitions and enforce seven-day retention';

    public function handle(): int
    {
        if (DB::getDriverName() === 'pgsql') {
            for ($offset = 1; $offset <= 7; $offset++) {
                $from = now('UTC')->addDays($offset)->startOfDay();
                $to = $from->copy()->addDay();
                $table = 'raw_market_events_'.$from->format('Ymd');
                DB::statement(sprintf(
                    "CREATE TABLE IF NOT EXISTS %s PARTITION OF raw_market_events FOR VALUES FROM ('%s') TO ('%s')",
                    $table, $from->format('Y-m-d H:i:sP'), $to->format('Y-m-d H:i:sP'),
                ));
            }
        }
        DB::table('raw_market_events')->where('received_at', '<', now()->subDays((int) config('research.books.raw_retention_days', 7)))->delete();

        return self::SUCCESS;
    }
}
