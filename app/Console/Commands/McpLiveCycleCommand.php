<?php

namespace App\Console\Commands;

use App\Services\Execution\LiveTradingCycle;
use Illuminate\Console\Command;

class McpLiveCycleCommand extends Command
{
    protected $signature = 'mcp:live-cycle {--once} {--account= : Local operator-owned connection ID}';

    protected $description = 'One bounded reconcile/collect/evaluate/dispatch cycle under explicitly enabled account automation';

    public function handle(LiveTradingCycle $cycle): int
    {
        if (! $this->option('once') || ($this->option('account') !== null && ! ctype_digit((string) $this->option('account')))) {
            $this->error('Use --once and an optional numeric --account.');

            return self::FAILURE;
        }
        $result = $cycle->tick($this->option('account') === null ? null : (int) $this->option('account'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
