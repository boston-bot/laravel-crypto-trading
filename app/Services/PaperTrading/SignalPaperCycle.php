<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\User;
use App\Services\Monitoring\AccountScope;

/** Uses later stored observations for old orders before creating any new intent. No collection or broker writes. */
final class SignalPaperCycle
{
    public function tick(?int $account = null): int
    {
        if (! config('paper.signal_handoff_enabled') || ! config('paper.signals_enabled') || ! config('paper.real_data_fills_enabled')) {
            return 0;
        }
        $count = 0;
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        foreach (McpConnection::whereIn('user_id', $operators)->where('status', 'connected')->where('account_role', 'agentic_monitoring')
            ->whereNotNull('bound_account_id')->when($account !== null, fn ($q) => $q->whereKey($account))->get() as $connection) {
            $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
            if (! $portfolio || ! RuleEligibilityPolicies::current($portfolio)) {
                continue;
            }
            if (config('paper.session_valuations_enabled')) {
                app(PaperSessionValuations::class)->capture($connection, $connection->user_id);
            }
            // Validation releases obsolete reservations before market inputs are required, including closed sessions.
            foreach (SimulationOrder::where('paper_portfolio_id', $portfolio->id)->whereIn('status', ['open', 'partial'])->orderBy('id')->get() as $order) {
                if (isset($order->intent['strategy_signal_id'])) {
                    app(RealDataPaperFills::class)->process($connection, $connection->user_id, $order->id);
                }
            }
            foreach (app(StrategySignals::class)->record($connection, $connection->user_id) as $signal) {
                app(SignalPaperHandoff::class)->process($connection, $connection->user_id, $signal->id);
                $count++;
            }
        }

        return $count;
    }
}
