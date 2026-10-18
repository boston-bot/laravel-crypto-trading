<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\RobinhoodWatchlists;
use Illuminate\Console\Command;
use Throwable;

class McpWatchlistCommand extends Command
{
    protected $signature = 'mcp:watchlist {--account= : Local connection ID} {--name=On Watch : Exact watchlist name (case-insensitive)}';

    protected $description = 'Read a Robinhood watchlist through the existing app-owned MCP connection; never changes policy or broker lists';

    public function handle(RobinhoodWatchlists $watchlists): int
    {
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->first();
        if (! $connection) {
            $this->error('Select a local connection owned by an allowed operator.');

            return self::FAILURE;
        }
        try {
            $this->line(json_encode($watchlists->named($connection, $this->option('name')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ReadFailure $failure) {
            $this->error('Watchlist unavailable: '.$failure->reason->value);
        } catch (Throwable) {
            $this->error('Watchlist unavailable. Check the integration and database configuration.');
        }

        return self::FAILURE;
    }
}
