<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationObservation;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;

/** The one simulated-ledger writer shared by equity and crypto paper orders. Callers hold the portfolio lock and decide admission. */
final class PaperLedger
{
    public function observation(Portfolio $portfolio, array $data, string $fingerprint, string $sampleKey): SimulationObservation
    {
        $observation = SimulationObservation::where('paper_portfolio_id', $portfolio->id)->where('source_key', $data['key'])->first();
        if ($observation) {
            abort_unless(hash_equals($observation->fingerprint, $fingerprint), 409, 'Observation key already identifies different evidence.');

            return $observation;
        }
        abort_if(SimulationObservation::where('paper_portfolio_id', $portfolio->id)->where('sample_key', $sampleKey)->exists(), 409, 'This source sample already has another observation key.');

        return SimulationObservation::create(['paper_portfolio_id' => $portfolio->id, 'source_key' => $data['key'],
            'sample_key' => $sampleKey, 'fingerprint' => $fingerprint, 'snapshot' => $data, 'source_at' => $data['source_at'], 'retrieved_at' => $data['retrieved_at'], 'recorded_at' => now()]);
    }

    public function commitment(SimulationOrder $order, Decimal $quantity): Decimal
    {
        $price = Decimal::from($order->price_bound);
        $schedule = $order->assumptions['cost_schedule'] ?? null;
        $fee = match (true) {
            // Notional venue fees need the bound price; the fee itself already rounds up.
            ($schedule['kind'] ?? null) === 'venue_notional_rate_v1' => app(TradingCosts::class)->fee($schedule, $order->side, $quantity, $price),
            $schedule !== null && $order->side === 'BUY' => app(TradingCosts::class)->buyReserve($schedule, $quantity),
            default => $this->fee($order, $quantity, $price, RoundingMode::CEILING),
        };

        return $quantity->multipliedBy($price)->plus($fee);
    }

    public function fee(SimulationOrder $order, Decimal $quantity, Decimal $price, RoundingMode $rounding = RoundingMode::DOWN): Decimal
    {
        if (isset($order->assumptions['cost_schedule'])) {
            return app(TradingCosts::class)->fee($order->assumptions['cost_schedule'], $order->side, $quantity, $price);
        }
        $rate = Decimal::from($order->assumptions['fee_bps'] ?? '0')->dividedBy(Decimal::from('10000'), 12);

        // Round fees down to ledger precision; reserve upward so partial fills cannot overdraw cash.
        return $quantity->multipliedBy(Decimal::from($order->assumptions['fee_per_share']))
            ->plus($quantity->multipliedBy($price)->multipliedBy($rate))->rounded(18, $rounding);
    }

    public function fill(Portfolio $portfolio, SimulationOrder $order, Decimal $quantity, Decimal $price, SimulationObservation $observation, string $eventKey, array $evaluation): void
    {
        $position = $this->position($portfolio, $order);
        $evaluation['position_before'] = $position->only(['quantity', 'reserved_quantity', 'cost_basis', 'realized_pnl']);
        $evaluation['cash_before'] = $portfolio->cash;
        $fee = $this->fee($order, $quantity, $price);
        $notional = $quantity->multipliedBy($price);
        if ($order->side === 'BUY') {
            $delta = $notional->plus($fee)->multipliedBy(Decimal::from('-1'));
            $remaining = Decimal::from($order->quantity)->minus(Decimal::from($order->filled_quantity))->minus($quantity);
            $release = Decimal::from($order->reserved_cash)->minus($this->commitment($order, $remaining));
            $order->reserved_cash = (string) Decimal::from($order->reserved_cash)->minus($release);
            $portfolio->reserved_cash = (string) Decimal::from($portfolio->reserved_cash)->minus($release);
            $position->quantity = (string) Decimal::from($position->quantity)->plus($quantity);
            $position->cost_basis = (string) Decimal::from($position->cost_basis)->plus($notional)->plus($fee);
        } else {
            $delta = $notional->minus($fee);
            if ($delta->compareTo(Decimal::from('0')) < 0) {
                $this->event($portfolio, $order, $eventKey, 'unfilled', 'fees_exceed_proceeds', $evaluation, $observation);

                return;
            }
            $basis = $quantity->compareTo(Decimal::from($position->quantity)) === 0 ? Decimal::from($position->cost_basis) :
                Decimal::from($position->cost_basis)->multipliedBy($quantity)->dividedBy(Decimal::from($position->quantity), 18);
            $position->quantity = (string) Decimal::from($position->quantity)->minus($quantity);
            $position->reserved_quantity = (string) Decimal::from($position->reserved_quantity)->minus($quantity);
            $position->cost_basis = (string) Decimal::from($position->cost_basis)->minus($basis);
            $position->realized_pnl = (string) Decimal::from($position->realized_pnl)->plus($delta)->minus($basis);
        }
        $position->save();
        $portfolio->cash = (string) Decimal::from($portfolio->cash)->plus($delta);
        $portfolio->ledger_version++;
        $portfolio->save();
        $order->filled_quantity = (string) Decimal::from($order->filled_quantity)->plus($quantity);
        $order->status = Decimal::from($order->filled_quantity)->compareTo(Decimal::from($order->quantity)) === 0 ? 'filled' : 'partial';
        $order->last_source_at = $observation->source_at;
        $order->save();
        $evaluation['position_after'] = $position->only(['quantity', 'reserved_quantity', 'cost_basis', 'realized_pnl']);
        $evaluation['cash_after'] = $portfolio->cash;
        $event = $this->event($portfolio, $order, $eventKey, 'fill', null, $evaluation, $observation, $quantity, $price, $fee, $delta);
        CashEntry::create(['paper_portfolio_id' => $portfolio->id, 'actor_id' => $order->actor_id, 'reference' => 'sim-fill:'.$event->id,
            'kind' => 'simulated_'.$order->side, 'amount' => (string) $delta, 'balance_after' => $portfolio->cash,
            'ledger_version' => $portfolio->ledger_version, 'recorded_at' => now()]);
    }

    public function close(Portfolio $portfolio, SimulationOrder $order, string $status, string $reason): SimulationOrder
    {
        if ($order->side === 'BUY') {
            $portfolio->reserved_cash = (string) Decimal::from($portfolio->reserved_cash)->minus(Decimal::from($order->reserved_cash));
            $order->reserved_cash = '0';
        } else {
            $position = $this->position($portfolio, $order);
            $position->reserved_quantity = (string) Decimal::from($position->reserved_quantity)->minus(Decimal::from($order->quantity)->minus(Decimal::from($order->filled_quantity)));
            $position->save();
        }
        $portfolio->ledger_version++;
        $portfolio->save();
        $order->update(['status' => $status, 'reserved_cash' => $order->reserved_cash]);
        $this->event($portfolio, $order, 'closed', $status, $reason, []);

        return $order;
    }

    public function position(Portfolio $portfolio, SimulationOrder $order): SimulationPosition
    {
        return SimulationPosition::firstOrCreate(['paper_portfolio_id' => $portfolio->id, 'symbol' => $order->symbol, 'sleeve' => $order->sleeve],
            ['quantity' => '0', 'reserved_quantity' => '0', 'cost_basis' => '0', 'realized_pnl' => '0']);
    }

    public function event(Portfolio $portfolio, SimulationOrder $order, string $key, string $kind, ?string $reason, array $evidence, ?SimulationObservation $observation = null, ?Decimal $quantity = null, ?Decimal $price = null, ?Decimal $fee = null, ?Decimal $delta = null): SimulationEvent
    {
        return SimulationEvent::create(['sim_order_id' => $order->id, 'observation_id' => $observation?->id, 'event_key' => $key,
            'kind' => $kind, 'reason' => $reason, 'quantity' => (string) ($quantity ?? Decimal::from('0')), 'price' => $price === null ? null : (string) $price,
            'fee' => (string) ($fee ?? Decimal::from('0')), 'cash_delta' => (string) ($delta ?? Decimal::from('0')),
            'ledger_version' => $portfolio->ledger_version, 'evidence' => $evidence + ['mode' => ($order->intent['origin'] ?? null) === 'real_data_paper_simulation' ? 'real_data_paper_simulation' : 'offline_synthetic_simulation', 'order_status' => $order->status], 'recorded_at' => now()]);
    }
}
