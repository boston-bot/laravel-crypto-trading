<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\StrategySignals;
use Illuminate\Console\Command;
use Throwable;

class McpStrategyCycleCommand extends Command
{
    protected $signature = 'mcp:strategy-cycle {--once} {--account= : Operator-owned local connection ID}';

    protected $description = 'Record deterministic five-minute paper signals from stored data; no collection, AI or orders';

    public function handle(StrategySignals $signals): int
    {
        if (! $this->option('once') || ($this->option('account') !== null && ! ctype_digit($this->option('account')))) {
            $this->error('Use --once and an optional numeric --account.');

            return self::FAILURE;
        }
        if (! config('paper.signals_enabled')) {
            $this->line('Strategy signals are disabled.');

            return self::SUCCESS;
        }
        try {
            if ($this->option('account') !== null) {
                $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
                $connection = McpConnection::whereIn('user_id', $operators)->findOrFail((int) $this->option('account'));
                $rows = $signals->record($connection, $connection->user_id);
                foreach ($rows as $row) {
                    $this->line(json_encode($row->only(['id', 'symbol', 'status', 'side', 'reason']), JSON_THROW_ON_ERROR));
                }
                $count = $rows->count();
            } else {
                $count = $signals->tick();
            }
            $this->line('Strategy evaluations: '.$count);

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Strategy evaluation unavailable. Check account authorization, applied settings and migrations.');

            return self::FAILURE;
        }
    }
}
