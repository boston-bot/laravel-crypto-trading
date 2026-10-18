<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\DecisionRun;
use App\Models\PaperTrading\ExecutionVersion;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\OrderHandoff;
use App\Services\Monitoring\AccountScope;

final class ExecutionOverview
{
    public function for(McpConnection $connection, ?array $paper): array
    {
        $scope = AccountScope::key($connection);
        if ($paper && ($paper['portfolio']->mcp_connection_id !== $connection->id || $paper['portfolio']->scope !== $scope)) {
            $paper = null;
        }
        $versions = $paper ? ExecutionVersion::where('paper_portfolio_id', $paper['portfolio']->id) : null;
        $runs = DecisionRun::where('mcp_connection_id', $connection->id)->where('scope', $scope);

        return ['latest' => $versions ? (clone $versions)->latest('version')->first() : null,
            'applied' => $paper ? ExecutionPolicies::current($paper['portfolio']) : null,
            'history' => $versions ? (clone $versions)->latest('version')->paginate(5, ['*'], 'execution_page')->withQueryString() : null,
            'inputs' => MarketInput::where('mcp_connection_id', $connection->id)->where('scope', $scope)->latest('id')->paginate(5, ['*'], 'market_inputs_page')->withQueryString(),
            'handoffs' => OrderHandoff::whereIn('decision_run_id', $runs->select('id'))->latest('id')->paginate(10, ['*'], 'handoffs_page')->withQueryString()];
    }
}
