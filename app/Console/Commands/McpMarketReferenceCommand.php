<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\PaperMarketReferences;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class McpMarketReferenceCommand extends Command
{
    protected $signature = 'mcp:market-reference {--account= : Local operator-owned connection ID} {--symbol=* : Symbols in the selected universe; default all}
        {--scheduled : Idempotent daily refresh for every bound operator connection}';

    protected $description = 'Record read-only US-listing and split-adjusted daily-history evidence; no broker call, policy change or order';

    public function handle(PaperMarketReferences $references): int
    {
        if ($this->option('scheduled')) {
            $this->line('Recorded market reference reads: '.$references->tick());

            return self::SUCCESS;
        }
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->whereNotNull('bound_account_id')->first();
        if (! $connection) {
            $this->error('Select an operator-owned bound connection.');

            return self::FAILURE;
        }
        if (! config('alpaca_data.enabled') || ! config('alpaca_data.reference_enabled')) {
            $this->error('Market reference reads are disabled.');

            return self::FAILURE;
        }
        $rows = $references->collect($connection, $connection->user_id, $this->option('symbol'), (string) Str::uuid());
        foreach ($rows as $row) {
            $p = $row->payload;
            // Facts and identifiers only; bars stay in the encrypted record.
            $this->line(json_encode(['symbol' => $row->symbol, 'kind' => $row->kind, 'status' => $row->status, 'reason' => $row->reason,
                'exchange' => $p['asset']['exchange'] ?? null, 'sessions' => $p['facts']['sessions'] ?? null,
                'last_session' => $p['facts']['last_session'] ?? null, 'average_dollar_volume_20' => $p['facts']['average_dollar_volume_20'] ?? null,
                'retrieved_at' => $p['retrieved_at']], JSON_THROW_ON_ERROR));
        }

        return $rows->every(fn ($row) => $row->status === 'complete') ? self::SUCCESS : self::FAILURE;
    }
}
