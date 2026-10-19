<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\CorporateActionRecord;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;

/** Accounting report only: historical cost is not a current portfolio valuation or return. */
final class SimulationOverview
{
    public function for(McpConnection $connection, ?array $paper): ?array
    {
        if (! $paper || $paper['portfolio']->mcp_connection_id !== $connection->id || $paper['portfolio']->scope !== AccountScope::key($connection)) {
            return null;
        }
        $id = $paper['portfolio']->id;
        $positions = SimulationPosition::where('paper_portfolio_id', $id)->orderBy('symbol')->orderBy('sleeve')->get();
        $basis = Decimal::from('0');
        $realized = Decimal::from('0');
        foreach ($positions as $position) {
            $basis = $basis->plus(Decimal::from($position->cost_basis));
            $realized = $realized->plus(Decimal::from($position->realized_pnl));
        }
        $orders = SimulationOrder::where('paper_portfolio_id', $id);
        $events = SimulationEvent::whereIn('sim_order_id', (clone $orders)->select('id'));
        $fees = Decimal::from('0');
        foreach ((clone $events)->where('kind', 'fill')->cursor() as $fill) {
            $fees = $fees->plus(Decimal::from($fill->fee));
        }

        return ['dividend_income' => (string) app(PaperIncome::class)->received($paper['portfolio']),
            'dividend_receivable' => (string) app(PaperIncome::class)->receivable($paper['portfolio']),
            'corporate_actions' => CorporateActionRecord::where('paper_portfolio_id', $id)->latest('id')->paginate(10, ['*'], 'paper_actions_page')->withQueryString(),
            'positions' => $positions, 'cost_basis' => (string) $basis, 'realized_pnl' => (string) $realized, 'fees' => (string) $fees,
            'valuation_status' => 'unavailable_no_current_marks', 'unrealized_pnl' => null, 'total_return' => null,
            'orders' => (clone $orders)->latest('id')->paginate(10, ['*'], 'sim_orders_page')->withQueryString(),
            'events' => (clone $events)->with('observation')->latest('id')->paginate(10, ['*'], 'sim_events_page')->withQueryString()];
    }
}
