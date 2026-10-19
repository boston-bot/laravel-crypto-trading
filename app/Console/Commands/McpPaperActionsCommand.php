<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\PaperCorporateActions;
use Illuminate\Console\Command;

class McpPaperActionsCommand extends Command
{
    protected $signature = 'mcp:paper-actions {--account= : Local operator-owned connection ID} {--input= : Newest stored broker input ID}';

    protected $description = 'Reconcile supported issuer actions into the paper ledger only; no network calls';

    public function handle(PaperCorporateActions $actions): int
    {
        if (! config('paper.corporate_actions_enabled')) {
            $this->error('Paper corporate-action processing is disabled.');

            return self::FAILURE;
        }
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->whereNotNull('bound_account_id')->first();
        if (! $connection || ! ctype_digit((string) $this->option('input'))) {
            $this->error('Select a bound operator-owned connection and stored input.');

            return self::FAILURE;
        }
        $records = $actions->process($connection, $connection->user_id, (int) $this->option('input'));
        $this->line(json_encode(['mode' => 'paper_corporate_action', 'records' => $records->map->only(['id', 'phase', 'status', 'reason', 'ledger_version'])->all()], JSON_THROW_ON_ERROR));

        return $records->contains('status', 'blocked') ? self::FAILURE : self::SUCCESS;
    }
}
