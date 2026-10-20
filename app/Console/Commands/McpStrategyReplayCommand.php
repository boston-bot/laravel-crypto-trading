<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\StrategyReplays;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class McpStrategyReplayCommand extends Command
{
    protected $signature = 'mcp:strategy-replay {--account= : Local operator-owned connection ID} {--years=3 : Replay window, 1-5 years}
        {--equity=500 : Starting virtual equity} {--symbol=* : Symbols; default the nominating watchlist symbols}';

    protected $description = 'Record a retrospective daily replay of the live strategy rules over split-adjusted history; never forward results, no order or ledger change';

    public function handle(StrategyReplays $replays): int
    {
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->whereNotNull('bound_account_id')->first();
        if (! $connection) {
            $this->error('Select an operator-owned bound connection.');

            return self::FAILURE;
        }
        $run = $replays->run($connection, $connection->user_id, (string) Str::uuid(), (int) $this->option('years'), (string) $this->option('equity'), $this->option('symbol'));
        $result = $run->payload['result'];
        $this->line(json_encode(['replay_id' => $run->id, 'status' => $run->status, 'reason' => $run->reason, 'label' => $run->payload['label'],
            'window' => [$run->start_date?->toDateString(), $run->end_date?->toDateString()], 'symbols' => count($run->payload['symbols']),
            'excluded' => $run->payload['excluded'], 'settings' => $run->payload['settings_source'], 'final_equity' => $result['final_equity'] ?? null,
            'stats' => $result['stats'] ?? null, 'counts' => $result['counts'] ?? null, 'benchmarks' => $result['benchmarks'] ?? null], JSON_THROW_ON_ERROR));

        return $run->status === 'complete' ? self::SUCCESS : self::FAILURE;
    }
}
