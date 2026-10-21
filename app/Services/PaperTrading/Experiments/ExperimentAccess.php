<?php

namespace App\Services\PaperTrading\Experiments;

use App\Models\McpConnection;
use App\Models\PaperTrading\StrategyExperiment;
use App\Models\User;
use App\Services\Monitoring\AccountScope;

final class ExperimentAccess
{
    public function authorize(McpConnection $connection, int $actor, ?StrategyExperiment $experiment = null): void
    {
        $current = $connection->fresh();
        abort_unless($current && $current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
        abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->bound_account_id &&
            $current->auth_generation === $connection->auth_generation && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account authorization changed.');
        if ($experiment !== null) {
            abort_unless($experiment->actor_id === $actor && $experiment->mcp_connection_id === $current->id && $experiment->scope === AccountScope::key($current) &&
                $experiment->definition['auth_generation'] === $current->auth_generation, 403, 'Experiment account scope changed.');
        }
    }
}
