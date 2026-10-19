<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\DecisionRun;
use App\Models\PaperTrading\ExecutionVersion;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\OrderHandoff;
use App\Models\PaperTrading\SignalExecutionPolicy;
use App\Models\PaperTrading\SignalOrderHandoff;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;

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

        return ['signal_policy' => $paper ? SignalExecutionPolicies::current($paper['portfolio']) : null,
            'signal_policy_latest' => $paper ? SignalExecutionPolicy::where('paper_portfolio_id', $paper['portfolio']->id)->latest('version')->first() : null,
            'signal_handoffs' => SignalOrderHandoff::where('paper_portfolio_id', $paper['portfolio']->id ?? 0)->latest('id')->paginate(10, ['*'], 'signal_handoffs_page')->withQueryString(),
            'readiness' => app(ExecutionDataReadiness::class)->for($connection, CarbonImmutable::now()), 'latest' => $versions ? (clone $versions)->latest('version')->first() : null,
            'applied' => $paper ? ExecutionPolicies::current($paper['portfolio']) : null,
            'history' => $versions ? (clone $versions)->latest('version')->paginate(5, ['*'], 'execution_page')->withQueryString() : null,
            'inputs' => MarketInput::where('mcp_connection_id', $connection->id)->where('scope', $scope)->latest('id')->paginate(5, ['*'], 'market_inputs_page')->withQueryString(),
            'handoffs' => OrderHandoff::whereIn('decision_run_id', $runs->select('id'))->latest('id')->paginate(10, ['*'], 'handoffs_page')->withQueryString()];
    }
}
