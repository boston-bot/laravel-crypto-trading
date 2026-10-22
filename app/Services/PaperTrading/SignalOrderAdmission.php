<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\Monitoring\Holding;
use App\Models\Monitoring\MarketSession;
use App\Models\Monitoring\Run;
use App\Models\Monitoring\Snapshot;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;

/** Rechecks the signal-specific settings at submission and every later fill. Caller holds connection/portfolio locks. */
final class SignalOrderAdmission
{
    public function reason(McpConnection $connection, Portfolio $portfolio, SimulationOrder $order, array $marks, bool $existing): ?string
    {
        $id = $order->intent['strategy_signal_id'] ?? null;
        if ($id === null) {
            return isset($order->assumptions['cost_schedule']) ? 'signal_costs_require_signal' : null;
        }
        if (! config('paper.signal_handoff_enabled') || ! config('paper.signals_enabled') || ! config('paper.real_data_fills_enabled')) {
            return 'signal_execution_disabled';
        }
        $policy = SignalExecutionPolicies::current($portfolio);
        if (! $policy || $policy->id !== ($order->intent['strategy_execution_policy_id'] ?? null)) {
            return 'signal_execution_policy_changed';
        }
        foreach (['slippage_bps', 'quote_max_age_seconds', 'cost_schedule'] as $key) {
            if (($order->assumptions[$key] ?? null) !== $policy->payload[$key]) {
                return 'signal_assumptions_changed';
            }
        }
        // Order type and fractional permission come from the applied execution and signal versions; fractional limits never exist.
        $execution = ExecutionPolicies::current($portfolio);
        $fractional = $order->type === 'market' && $policy->payload['allow_fractional'] === true;
        if (($order->assumptions['fee_per_share'] ?? null) !== '0' || ($order->assumptions['fee_bps'] ?? null) !== '0' || $order->type !== ($execution?->payload['order_type'] ?? null) ||
            $order->assumptions['allow_fractional'] !== $fractional || $order->assumptions['allow_fractional_limit'] !== false) {
            return 'signal_assumptions_changed';
        }
        if (! TradingCosts::valid($policy->payload['cost_schedule'], CarbonImmutable::now())) {
            return 'cost_schedule_expired_or_unreviewed';
        }
        $signal = StrategySignal::whereKey($id)->where('paper_portfolio_id', $portfolio->id)->where('symbol', $order->symbol)->first();
        $cycle = $signal ? StrategyCycle::find($signal->cycle_id) : null;
        if (! $signal || ! $cycle || $cycle->auth_generation !== $connection->auth_generation || $signal->status !== 'signal' || $signal->side !== $order->side ||
            $signal->rule_version !== SignalRules::VERSION || $signal->recorded_at->gt($order->accepted_at)) {
            return 'signal_provenance_invalid';
        }
        if (! $existing && ($signal->cutoff->gt(CarbonImmutable::now()) || $signal->cutoff->lte(CarbonImmutable::now()->subMinutes(5)))) {
            return 'signal_expired';
        }
        if (! $existing && isset($marks[$order->symbol])) {
            $maxEntry = null;
            if ($order->side === 'BUY') {
                $profile = app(SymbolProfiles::class)->latest($connection, $order->symbol, CarbonImmutable::now());
                if (! $profile || ! isset($profile->payload['levels']['max_entry'])) {
                    return 'entry_profile_changed_or_blocked';
                }
                $maxEntry = $profile->payload['levels']['max_entry'];
            }
            $expected = SignalOrderPrice::bound($order->type, $order->side, $marks[$order->symbol]->data, $maxEntry, $policy->payload);
            if ($expected->compareTo(Decimal::from($order->price_bound)) !== 0) {
                return 'signal_limit_offset_mismatch';
            }
        }
        if ($order->side === 'SELL') {
            $held = app(SignalPositionFacts::class)->inspect($portfolio, $order->symbol, CarbonImmutable::now());
            if (! $held['context'] || $held['context']['entry_order_id'] !== ($signal->payload['evidence']['report']['position']['context']['entry_order_id'] ?? null)) {
                return 'exit_position_episode_changed';
            }

            return null; // Entry filters and portfolio risk budgets never gate an existing position's exit.
        }
        if (($order->assumptions['session_open_equity'] ?? null) === null || isset($order->assumptions['baseline_kind'])) {
            return 'verified_session_baseline_unavailable';
        }
        if (! $existing) {
            $session = app(MarketCalendar::class)->at(CarbonImmutable::now());
            if ($session['status'] !== 'regular') {
                return 'outside_verified_session';
            }
            $baseline = app(PaperSessionValuations::class)->resolve($connection, $portfolio, $session, $order->id);
            if (! $baseline || Decimal::from($baseline['equity'])->compareTo(Decimal::from($order->assumptions['session_open_equity'])) !== 0) {
                return 'verified_session_baseline_unavailable_or_changed';
            }
            $snapshot = Snapshot::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('status', 'complete')
                ->whereIn('run_id', Run::where('mcp_connection_id', $connection->id)->where('auth_generation', $connection->auth_generation)->select('id'))->latest('publication_version')->first();
            if (! $snapshot || $snapshot->retrieved_at->isFuture() || $snapshot->retrieved_at->lte(CarbonImmutable::now()->subMinutes(5))) {
                return 'broker_positions_unverified';
            }
            if (Holding::where('snapshot_id', $snapshot->id)->where('symbol', $order->symbol)->get()->contains(fn ($h) => Decimal::from($h->quantity)->compareTo(Decimal::from('0')) !== 0)) {
                return 'broker_symbol_already_held';
            }
            $facts = app(SignalPositionFacts::class)->inspect($portfolio, $order->symbol, CarbonImmutable::now());
            if ($facts['fill_ids'] !== ($signal->payload['evidence']['report']['position']['fill_ids'] ?? [])) {
                return 'signal_position_history_changed';
            }
        }
        $position = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('symbol', $order->symbol)->get();
        $held = Decimal::from('0');
        foreach ($position as $p) {
            $held = $held->plus(Decimal::from($p->quantity));
        }
        if ((! $existing && $held->compareTo(Decimal::from('0')) > 0) || ($existing && $held->compareTo(Decimal::from($order->filled_quantity)) !== 0)) {
            return 'symbol_already_held';
        }
        $pending = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('id', '!=', $order->id)->whereIn('status', ['open', 'partial'])->get();
        if ($pending->contains('symbol', $order->symbol)) {
            return 'symbol_has_open_order';
        }
        $occupied = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->get()->filter(fn ($p) => Decimal::from($p->quantity)->compareTo(Decimal::from('0')) > 0)->pluck('symbol')->all();
        $occupied = array_values(array_unique([...$occupied, ...$pending->where('side', 'BUY')->pluck('symbol')->all()]));
        $other = array_diff($occupied, [$order->symbol]);
        if (count($other) >= $policy->payload['max_open_positions']) {
            return 'maximum_open_positions';
        }
        $now = CarbonImmutable::now();
        $input = app(PaperMarketInputs::class)->latest($connection, $order->symbol, $now);
        if (! $input || ! isset($marks[$order->symbol])) {
            return 'broker_observation_missing';
        }
        $profile = app(SymbolProfiles::class)->latest($connection, $order->symbol, $now);
        $originalProfile = $signal->profile_id;
        if (! $profile || $profile->id !== $originalProfile || $profile->status !== 'complete' || $profile->payload['market']['filter'] !== 'on') {
            return 'entry_profile_changed_or_blocked';
        }
        $references = app(PaperMarketReferences::class);
        $history = $references->verifiedHistory($connection, $order->symbol, $now);
        $benchmark = $references->verifiedHistory($connection, 'SPY', $now);
        if (! $history['read'] || ! $benchmark['read'] || $profile->history_read_id !== $history['read_id'] || $profile->benchmark_read_id !== $benchmark['read_id']) {
            return 'entry_profile_history_unavailable_or_changed';
        }
        $previous = MarketSession::where('exchange', 'XNYS')->where('closes_at', '<', $now)->where('retrieved_at', '<=', $now)->latest('session_date')->first();
        if (! $previous || $profile->session_date->toDateString() !== $previous->session_date) {
            return 'entry_profile_session_stale';
        }
        $earnings = config('robinhood_mcp.earnings_enabled') ? app(EarningsCalendar::class)->status($connection, $order->symbol, $now, SignalParameters::current()['values']['earnings_blackout_sessions']) : ['status' => 'unavailable'];
        if ($earnings['status'] !== 'pass') {
            return 'entry_earnings_blocked_or_unknown';
        }
        $quote = $marks[$order->symbol]->data;
        $entry = app(SignalRules::class)->entry($profile->payload, $quote['ask'], $signal->payload['parameters']['values']);
        if ($entry['status'] !== 'signal') {
            return 'entry_trigger_no_longer_valid';
        }
        $max = Decimal::from($profile->payload['levels']['max_entry']);
        if ($order->type === 'market') {
            // Submission-time price guard. The spread limit was already enforced above by the applied eligibility rules,
            // and the entry rule rechecked the maximum chase on this fresh ask; the stored maximum entry is rounded down.
            if (Decimal::from($quote['ask'])->compareTo($max) > 0) {
                return 'market_price_guard_failed';
            }
        } elseif (Decimal::from($order->price_bound)->compareTo($max) > 0) {
            return 'entry_limit_exceeds_maximum_chase';
        }
        $equity = Decimal::from($portfolio->cash)->plus(app(PaperIncome::class)->receivable($portfolio));
        foreach (SimulationPosition::where('paper_portfolio_id', $portfolio->id)->get() as $p) {
            if (Decimal::from($p->quantity)->compareTo(Decimal::from('0')) > 0) {
                if (! isset($marks[$p->symbol])) {
                    return 'position_mark_unknown';
                }
                $equity = $equity->plus(Decimal::from($p->quantity)->multipliedBy(Decimal::from($marks[$p->symbol]->data['bid'])));
            }
        }
        $capacity = app(SignalPortfolioCapacity::class)->for($portfolio, $equity, $order->sleeve, $marks, $order);
        $cash = $capacity['cash'];
        $bound = Decimal::from($order->price_bound);
        $stop = $bound->minus(Decimal::from($signal->payload['result']['facts']['entry_atr'])->multipliedBy(Decimal::from($signal->payload['parameters']['values']['initial_stop_atr'])));
        $size = app(RiskSizer::class)->calculate($equity, $cash, $bound, $stop, $policy->payload, $capacity['sleeve_notional'], SignalOrderPrice::scale($order->type, $policy->payload, $quote));
        $remaining = Decimal::from($order->quantity)->minus(Decimal::from($order->filled_quantity));
        if (Decimal::from($order->quantity)->multipliedBy(Decimal::from($order->price_bound))->multipliedBy(Decimal::from('100'))
            ->compareTo($equity->multipliedBy(Decimal::from($policy->payload['max_position_pct']))) > 0) {
            return 'signal_position_limit_exceeded';
        }
        if ($remaining->compareTo(Decimal::from($size['quantity'])) > 0 ||
            Decimal::from($order->quantity)->multipliedBy(Decimal::from($size['risk_per_share'] ?? '0'))
                ->compareTo($equity->multipliedBy(Decimal::from($policy->payload['risk_per_trade_pct']))->dividedBy(Decimal::from('100'), 18)) > 0) {
            return 'signal_risk_size_exceeded';
        }

        return null;
    }
}
