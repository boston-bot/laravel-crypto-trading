<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\StrategySignals;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Manual, stored-data exit evaluation only. Never dispatches an order or changes activation settings. */
class McpLiveExitsCommand extends Command
{
    protected $signature = 'mcp:live-exits {--account= : Local operator-owned connection ID}';

    protected $description = 'Record deterministic exit evaluations for broker-confirmed app positions; no broker calls or orders';

    public function handle(StrategySignals $signals): int
    {
        $connection = McpConnection::whereIn('user_id', User::whereIn('email', config('mcp.operator_emails'))->select('id'))
            ->whereKey($this->option('account'))->first();
        if (! $connection) {
            $this->error('Select an operator-owned connection.');

            return self::FAILURE;
        }
        try {
            $rows = $signals->recordLiveExits($connection, $connection->user_id);
        } catch (HttpException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['mode' => 'live_exit_signal_evidence', 'signals' => $rows->map->only(['id', 'symbol', 'status', 'side', 'reason'])->all()], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
