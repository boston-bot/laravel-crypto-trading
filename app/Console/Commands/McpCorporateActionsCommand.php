<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\MarketData\Alpaca\CorporateActions;
use Illuminate\Console\Command;

class McpCorporateActionsCommand extends Command
{
    protected $signature = 'mcp:corporate-actions {--account= : Local operator-owned connection ID} {--symbol= : Exact equity symbol} {--start= : Process date YYYY-MM-DD} {--end= : Process date YYYY-MM-DD}';

    protected $description = 'Check read-only Alpaca action access and query coverage; no orders or ledger writes';

    public function handle(CorporateActions $actions): int
    {
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->whereNotNull('bound_account_id')->first();
        if (! $connection) {
            $this->error('Select an operator-owned bound connection.');

            return self::FAILURE;
        }
        $read = $actions->read((string) $this->option('symbol'), (string) $this->option('start'), (string) $this->option('end'));
        $this->line(json_encode(array_diff_key($read, ['events' => true]) + ['event_count' => count($read['events']),
            'incomplete_event_count' => count(array_filter($read['events'], fn ($e) => $e['missing_fields'] !== []))], JSON_THROW_ON_ERROR));

        return $read['complete'] ? self::SUCCESS : self::FAILURE;
    }
}
