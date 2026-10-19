<?php

namespace App\Services\PaperTrading;

use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategySignal;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;

/** Reconstruct only the current paper episode. Broker positions and legacy/synthetic fills are never adopted. */
final class SignalPositionFacts
{
    public function inspect(Portfolio $portfolio, string $symbol, CarbonImmutable $cutoff): array
    {
        $positions = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('symbol', $symbol)->get();
        $quantity = Decimal::from('0');
        foreach ($positions as $position) {
            $quantity = $quantity->plus(Decimal::from($position->quantity));
        }
        $fills = SimulationEvent::where('kind', 'fill')
            ->whereIn('sim_order_id', SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('symbol', $symbol)->select('id'))
            ->where('recorded_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->orderBy('id')->get();
        $running = Decimal::from('0');
        $buys = [];
        $exit = null;
        $entryAt = null;
        $ids = [];
        foreach ($fills as $fill) {
            $order = SimulationOrder::findOrFail($fill->sim_order_id);
            $ids[] = $fill->id;
            if ($order->side === 'BUY') {
                if ($running->compareTo(Decimal::from('0')) === 0) {
                    $buys = [];
                    $entryAt = $fill->recorded_at;
                }
                $buys[$order->id] = $order;
                $running = $running->plus(Decimal::from($fill->quantity));
            } else {
                $running = $running->minus(Decimal::from($fill->quantity));
                if ($running->compareTo(Decimal::from('0')) === 0) {
                    $exit = $fill->recorded_at->toISOString();
                    $buys = [];
                }
            }
        }
        $result = ['held' => $quantity->compareTo(Decimal::from('0')) > 0, 'quantity' => (string) $quantity,
            'positions' => $positions->map->only(['id', 'sleeve', 'quantity', 'reserved_quantity', 'cost_basis', 'blocked_reason'])->all(),
            'fill_ids' => $ids, 'last_exit_at' => $exit, 'context' => null, 'reason' => null];
        if (! $result['held']) {
            return $result;
        }
        if ($positions->contains(fn ($p) => $p->blocked_reason !== null)) {
            return array_replace($result, ['reason' => 'strategy_position_requires_review']);
        }
        if ($running->compareTo($quantity) !== 0 || count($buys) !== 1) {
            return array_replace($result, ['reason' => 'strategy_position_history_unverified']);
        }
        $order = array_values($buys)[0];
        $signal = StrategySignal::whereKey($order->intent['strategy_signal_id'] ?? 0)->where('paper_portfolio_id', $portfolio->id)
            ->where('symbol', $symbol)->where('status', 'signal')->where('side', 'BUY')->first();
        if (! $signal || $signal->rule_version !== SignalRules::VERSION || $signal->recorded_at->gt($order->accepted_at) ||
            $order->intent['origin'] !== 'real_data_paper_simulation' || $positions->where('sleeve', $order->sleeve)->count() !== 1) {
            return array_replace($result, ['reason' => 'position_not_managed_by_strategy']);
        }
        // Actual partial fills, not the signal ask, determine entry. Fees are excluded from the stop reference.
        $entryFills = $fills->where('sim_order_id', $order->id);
        $notional = Decimal::from('0');
        $bought = Decimal::from('0');
        foreach ($entryFills as $fill) {
            $bought = $bought->plus(Decimal::from($fill->quantity));
            $notional = $notional->plus(Decimal::from($fill->quantity)->multipliedBy(Decimal::from($fill->price)));
        }
        $entry = $notional->dividedBy($bought, 18);
        $atr = Decimal::from($signal->payload['result']['facts']['entry_atr']);
        $p = $signal->payload['parameters']['values'];
        $stop = $entry->minus($atr->multipliedBy(Decimal::from($p['initial_stop_atr'])));
        if ($stop->compareTo(Decimal::from('0')) <= 0) {
            return array_replace($result, ['reason' => 'strategy_position_stop_invalid']);
        }
        $result['context'] = ['entry_order_id' => $order->id, 'entry_signal_id' => $signal->id, 'entry_at' => $entryAt->toISOString(),
            'entry_price' => (string) $entry, 'entry_atr' => (string) $atr, 'initial_stop' => (string) $stop,
            'parameters' => $p, 'parameters_version' => $signal->parameters_version];

        return $result;
    }
}
