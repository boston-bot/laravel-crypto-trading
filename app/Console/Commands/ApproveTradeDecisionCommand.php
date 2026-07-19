<?php

namespace App\Console\Commands;

use App\Enums\TradingDecisionStatus;
use App\Jobs\SubmitBrokerOrderJob;
use App\Models\TradeDecision;
use Illuminate\Console\Command;

class ApproveTradeDecisionCommand extends Command
{
    protected $signature = 'trading:approve-decision {decisionId : Trade decision ID} {--actor=system : Approver identity}';

    protected $description = 'Approve a pending trade decision and queue order submission.';

    public function handle(): int
    {
        $decision = TradeDecision::query()->find((int) $this->argument('decisionId'));
        if ($decision === null) {
            $this->error('Trade decision not found.');

            return self::FAILURE;
        }

        if ($decision->status !== TradingDecisionStatus::AWAITING_HUMAN_APPROVAL) {
            $this->error('Trade decision is not awaiting human approval.');

            return self::FAILURE;
        }

        $decision->update([
            'status' => TradingDecisionStatus::APPROVED->value,
            'approved_by' => (string) $this->option('actor'),
            'approved_at' => now(),
        ]);

        SubmitBrokerOrderJob::dispatch($decision->id);

        $this->info("Trade decision {$decision->id} approved and submission queued.");

        return self::SUCCESS;
    }
}
