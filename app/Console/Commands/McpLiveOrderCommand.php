<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\PaperTrading\StrategySignal;
use App\Models\User;
use App\Services\Execution\ExecutionDispatcher;
use App\Services\Execution\ExecutionJournal;
use App\Services\Execution\ExecutionRecoveryFence;
use App\Services\Execution\SubmissionChecks;
use App\Services\Monitoring\AccountScope;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Owner-run, one signal at a time: plan from stored broker-confirmed facts, stage the immutable intent, then (unless
 * --stage-only) dispatch it through the live boundary. Nothing schedules this command.
 */
class McpLiveOrderCommand extends Command
{
    protected $signature = 'mcp:live-order {--account= : Local operator-owned connection ID} {--signal= : Recorded deterministic signal ID}
        {--stage-only : Plan and stage the intent without dispatching it}';

    protected $description = 'Plan, stage and (unless --stage-only) send one live order for a recorded signal; denied unless every live gate passes';

    public function handle(SubmissionChecks $checks, ExecutionJournal $journal, ExecutionDispatcher $dispatcher, ExecutionRecoveryFence $fence): int
    {
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->whereNotNull('bound_account_id')->first();
        $signal = ctype_digit((string) $this->option('signal')) && $connection
            ? StrategySignal::whereKey((int) $this->option('signal'))->where('mcp_connection_id', $connection->id)->first() : null;
        if (! $connection || ! $signal) {
            $this->error('Select an operator-owned bound connection and one of its recorded signals.');

            return self::FAILURE;
        }
        $plan = $checks->plan($connection, $signal);
        $reason = $plan['reason'] ?? $fence->reason(AccountScope::key($connection));
        if ($reason !== null) {
            $this->line(json_encode(['signal_id' => $signal->id, 'outcome' => 'denied', 'reason' => $reason], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
        try {
            // The submission deadline stays inside the signal's five-minute window; the journal refuses anything later.
            $deadline = $signal->cutoff->addMinutes(5)->subSecond();
            $intent = $journal->stage($connection, $connection->user_id, $signal->id, $plan['draft'] + ['expires_at' => $deadline->utc()->toIso8601String()]);
            $outcome = $this->option('stage-only') ? 'staged' : $dispatcher->dispatch($connection, $connection->user_id, $intent->id);
        } catch (HttpException $refusal) {
            $this->line(json_encode(['signal_id' => $signal->id, 'outcome' => 'denied', 'reason' => $refusal->getMessage()], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
        $this->line(json_encode(['signal_id' => $signal->id, 'intent_id' => $intent->id, 'outcome' => $outcome, 'symbol' => $intent->payload['symbol'],
            'side' => $intent->payload['side'], 'quantity' => $intent->payload['quantity'], 'type' => $intent->payload['type']], JSON_THROW_ON_ERROR));

        return in_array($outcome, ['staged', 'accepted', 'approval_pending'], true) ? self::SUCCESS : self::FAILURE;
    }
}
