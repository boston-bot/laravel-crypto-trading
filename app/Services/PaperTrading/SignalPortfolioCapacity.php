<?php

namespace App\Services\PaperTrading;

use App\Models\PaperTrading\PolicyApplication;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Support\Mcp\Decimal;

/** Preserve the owner's existing cash floor and sleeve allocation alongside the tighter signal risk limits. */
final class SignalPortfolioCapacity
{
    public function for(Portfolio $portfolio, Decimal $equity, string $sleeve, array $marks, ?SimulationOrder $own = null): array
    {
        $policy = PolicyApplication::where('paper_portfolio_id', $portfolio->id)->where('revision', $portfolio->policy_revision)->firstOrFail();
        $rules = $policy->snapshot['rules'];
        $cash = Decimal::from($portfolio->cash)->minus(Decimal::from($portfolio->reserved_cash));
        if ($own) {
            $cash = $cash->plus(Decimal::from($own->reserved_cash));
        }
        $cash = $cash->minus($equity->multipliedBy(Decimal::from($rules['cash_pct']))->dividedBy(Decimal::from('100'), 18));
        $capacity = $equity->multipliedBy(Decimal::from($rules[$sleeve.'_pct']))->dividedBy(Decimal::from('100'), 18);
        foreach (SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('sleeve', $sleeve)->get() as $p) {
            if (Decimal::from($p->quantity)->compareTo(Decimal::from('0')) > 0) {
                $capacity = $capacity->minus(Decimal::from($p->quantity)->multipliedBy(Decimal::from($marks[$p->symbol]->data['bid'])));
            }
        }
        foreach (SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('sleeve', $sleeve)->where('side', 'BUY')
            ->whereIn('status', ['open', 'partial'])->when($own, fn ($q) => $q->where('id', '!=', $own->id))->get() as $pending) {
            $capacity = $capacity->minus(Decimal::from($pending->quantity)->minus(Decimal::from($pending->filled_quantity))->multipliedBy(Decimal::from($pending->price_bound)));
        }

        return ['cash' => $cash, 'sleeve_notional' => $capacity];
    }
}
