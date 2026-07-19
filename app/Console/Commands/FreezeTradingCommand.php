<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class FreezeTradingCommand extends Command
{
    protected $signature = 'trading:freeze {state : on|off}';

    protected $description = 'Toggle the global trading kill switch.';

    public function handle(): int
    {
        $state = strtolower((string) $this->argument('state'));
        $cacheKey = (string) config('trading.kill_switch_cache_key', 'trading:frozen');

        if ($state === 'on') {
            Cache::forever($cacheKey, true);
            $this->warn('Trading freeze enabled.');

            return self::SUCCESS;
        }

        if ($state === 'off') {
            Cache::forget($cacheKey);
            $this->info('Trading freeze disabled.');

            return self::SUCCESS;
        }

        $this->error('State must be "on" or "off".');

        return self::FAILURE;
    }
}
