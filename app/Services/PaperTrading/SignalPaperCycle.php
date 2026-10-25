<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;

/** Uses later stored observations for old orders before new intents. Batched quotes, per-record local paper processing; never broker writes. */
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
            if (! config('paper.batch_collection_enabled')) {
                $count += $this->evaluate($connection);
            }
        }

        if (config('paper.batch_collection_enabled')) {
            app(BatchMarketInputCollector::class)->tick($account, true, function ($connection, $symbols) use (&$count) {
                // Consume each stored symbol before gathering the next, even within a quote batch.
                // Opening evidence and old-order fills precede new intents. Existing order-type fill rules remain in force.
                $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
                if (! $portfolio || ! RuleEligibilityPolicies::current($portfolio)) {
                    return;
                }
                if (config('paper.session_valuations_enabled')) {
                    app(PaperSessionValuations::class)->capture($connection, $connection->user_id);
                }
                foreach (SimulationOrder::where('paper_portfolio_id', $portfolio->id)->whereIn('symbol', $symbols)->whereIn('status', ['open', 'partial'])->orderBy('id')->get() as $order) {
                    if (isset($order->intent['strategy_signal_id'])) {
                        app(RealDataPaperFills::class)->process($connection, $connection->user_id, $order->id);
                    }
                }
                $count += $this->evaluate($connection, $symbols);
            });
        }

        return $count;
    }

    private function evaluate(McpConnection $connection, ?array $symbols = null): int
    {
        if ($symbols !== null) {
            // Membership may change during collection. Drop removed unheld symbols without delaying held-position exits.
            $symbols = array_values(array_intersect($symbols, app(SymbolUniverse::class)->reads($connection, CarbonImmutable::now())));
            if ($symbols === []) {
                return 0;
            }
        }
        $signals = app(StrategySignals::class)->record($connection, $connection->user_id, $symbols);
        foreach ($signals as $signal) {
            app(SignalPaperHandoff::class)->process($connection, $connection->user_id, $signal->id);
        }

        return $signals->count();
    }
}
