<?php

namespace App\Console\Commands;

use App\Services\PaperTrading\SignalPaperCycle;
use Illuminate\Console\Command;
use Throwable;

class McpSignalPaperCycleCommand extends Command
{
    protected $signature = 'mcp:signal-paper-cycle {--once} {--account= : Local operator connection ID}';

    protected $description = 'Process stored-data signal paper handoffs and later-observation fills; never broker orders';

    public function handle(SignalPaperCycle $cycle): int
    {
        if (! $this->option('once') || ($this->option('account') !== null && ! ctype_digit($this->option('account')))) {
            $this->error('Use --once and an optional numeric --account.');

            return self::FAILURE;
        }
        try {
            $count = $cycle->tick($this->option('account') === null ? null : (int) $this->option('account'));
            $this->line('Signal paper outcomes: '.$count);

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Signal paper processing unavailable. Review settings and stored evidence. No brokerage operation was performed.');

            return self::FAILURE;
        }
    }
}
