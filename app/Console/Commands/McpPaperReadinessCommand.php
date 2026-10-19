<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\ExecutionDataReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class McpPaperReadinessCommand extends Command
{
    protected $signature = 'mcp:paper-readiness {--account= : Local operator-owned connection ID}';

    protected $description = 'Audit stored execution-data coverage without network calls, policy changes or orders';

    public function handle(ExecutionDataReadiness $readiness): int
    {
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->whereNotNull('bound_account_id')->first();
        if (! $connection) {
            $this->error('Select a bound connection owned by an allowed operator.');

            return self::FAILURE;
        }
        $report = DB::transaction(function () use ($connection, $readiness) {
            // A nested invocation inherits the caller's transaction; PostgreSQL cannot change its isolation after queries.
            if (DB::connection()->getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $readiness->for($connection->fresh(), CarbonImmutable::now());
        });
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['ready_count'] === $report['symbol_count'] && $report['symbol_count'] > 0 ? self::SUCCESS : self::FAILURE;
    }
}
