<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SignalOrderHandoff;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Only recorded deterministic signals can cross this paper boundary. No model target weights or broker mutations. */
final class SignalPaperHandoff
{
    public function process(McpConnection $connection, int $actor, int $signalId): SignalOrderHandoff
    {
        abort_unless(config('paper.signal_handoff_enabled'), 409, 'Signal paper handoff is disabled.');

        return DB::transaction(function () use ($connection, $actor, $signalId) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->bound_account_id &&
                $current->auth_generation === $connection->auth_generation && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account authorization changed.');
            $portfolio = Portfolio::where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->lockForUpdate()->firstOrFail();
            $signal = StrategySignal::whereKey($signalId)->where('mcp_connection_id', $current->id)->where('paper_portfolio_id', $portfolio->id)->firstOrFail();
            if ($prior = SignalOrderHandoff::where('signal_id', $signalId)->first()) {
                return $prior;
            }
            $policy = SignalExecutionPolicies::current($portfolio);
            $evidence = ['signal_id' => $signal->id, 'signal_input_fingerprint' => $signal->input_fingerprint, 'market_input_ids' => [],
                'ledger_version_before' => $portfolio->ledger_version];
            $record = function ($status, $reason, $order = null) use ($portfolio, $signal, $policy, $actor, &$evidence) {
                return SignalOrderHandoff::create(['paper_portfolio_id' => $portfolio->id, 'signal_id' => $signal->id,
                    'signal_policy_id' => $policy?->id, 'sim_order_id' => $order?->id, 'actor_id' => $actor, 'status' => $status, 'reason' => $reason,
                    'payload' => $evidence + ['mode' => 'signal_paper_simulation', 'broker_executable' => false], 'recorded_at' => now()]);
            };
            if ($signal->status !== 'signal') {
                return $record('no_order', $signal->reason);
            }
            try {
                if (! config('paper.signals_enabled') || ! config('paper.real_data_fills_enabled')) {
                    throw new DecisionFailure('signal_or_fills_disabled', 'unavailable');
                }
                if (! $policy) {
                    throw new DecisionFailure('signal_execution_policy_unapplied', 'unavailable');
                }
                $settings = $policy->payload;
                if (! TradingCosts::valid($settings['cost_schedule'], CarbonImmutable::now())) {
                    throw new DecisionFailure('cost_schedule_expired_or_unreviewed', 'unavailable');
                }
                $cycle = StrategyCycle::findOrFail($signal->cycle_id);
                $execution = ExecutionPolicies::current($portfolio);
                if ($cycle->auth_generation !== $current->auth_generation || $signal->rule_version !== SignalRules::VERSION || $signal->cutoff->isFuture() ||
                    $signal->cutoff->lte(CarbonImmutable::now()->subMinutes(5)) || $execution->id !== $signal->payload['evidence']['execution_version_id']) {
                    throw new DecisionFailure('signal_stale_or_settings_changed', 'unavailable');
                }
                $buy = $signal->side === 'BUY';
                if (! $execution->payload[$buy ? 'allow_entries' : 'allow_exits']) {
                    throw new DecisionFailure('owner_execution_permission_denied');
                }
                $session = app(MarketCalendar::class)->at(CarbonImmutable::now());
                if ($session['status'] !== 'regular') {
                    throw new DecisionFailure('outside_verified_session', 'unavailable');
                }
                $positions = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->get();
                $symbols = array_unique([$signal->symbol, ...$positions->filter(fn ($p) => Decimal::from($p->quantity)->compareTo(Decimal::from('0')) > 0)->pluck('symbol')->all()]);
                $marks = [];
                foreach ($symbols as $symbol) {
                    $input = app(PaperMarketInputs::class)->latest($current, $symbol, CarbonImmutable::now());
                    if (! $input) {
                        throw new DecisionFailure('broker_observation_missing', 'unavailable');
                    }
                    $evidence['market_input_ids'][$symbol] = $input->id;
                    $marks[$symbol] = BrokerSimulationQuote::fromStored($current, $input->id, CarbonImmutable::now(), $settings['quote_max_age_seconds']);
                }
                $pending = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('symbol', $signal->symbol)->whereIn('status', ['open', 'partial'])->get();
                foreach ($pending as $open) {
                    if ($buy || $open->side !== 'SELL' || ! isset($open->intent['strategy_signal_id'])) {
                        throw new DecisionFailure('symbol_has_open_order');
                    }
                }
                // Exits keep their intent history; cancel/release an older unfilled remainder before repricing a new cycle.
                foreach ($pending as $open) {
                    app(OfflineSimulator::class)->cancel($current, $actor, $open->id);
                }
                $portfolio->refresh();
                $positions = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->get();
                $equity = Decimal::from($portfolio->cash)->plus(app(PaperIncome::class)->receivable($portfolio));
                foreach ($positions as $position) {
                    if (isset($marks[$position->symbol])) {
                        $equity = $equity->plus(Decimal::from($position->quantity)->multipliedBy(Decimal::from($marks[$position->symbol]->data['bid'])));
                    }
                }
                $reference = Decimal::from($marks[$signal->symbol]->data[$buy ? 'ask' : 'bid']);
                $offset = Decimal::from($settings['limit_offset_bps'])->dividedBy(Decimal::from('10000'), 12);
                $bound = $reference->multipliedBy(Decimal::from('1')->plus($offset->multipliedBy(Decimal::from($buy ? '1' : '-1'))))
                    ->rounded(8, $buy ? RoundingMode::CEILING : RoundingMode::FLOOR);
                if ($buy) {
                    $profile = app(SymbolProfiles::class)->latest($current, $signal->symbol, CarbonImmutable::now());
                    if (! $profile || $profile->id !== $signal->profile_id) {
                        throw new DecisionFailure('entry_profile_changed_or_blocked', 'unavailable');
                    }
                    $max = Decimal::from($profile->payload['levels']['max_entry']);
                    if ($bound->compareTo($max) > 0) {
                        $bound = $max;
                    }
                    $capacity = app(SignalPortfolioCapacity::class)->for($portfolio, $equity, $execution->payload['decision_sleeve'], $marks);
                    $cash = $capacity['cash'];
                    $stop = $bound->minus(Decimal::from($signal->payload['result']['facts']['entry_atr'])->multipliedBy(Decimal::from($signal->payload['parameters']['values']['initial_stop_atr'])));
                    $size = app(RiskSizer::class)->calculate($equity, $cash, $bound, $stop, $settings, $capacity['sleeve_notional']);
                    $quantity = Decimal::from($size['quantity']);
                    $evidence['sizing'] = $size + ['equity' => (string) $equity, 'cash_available' => (string) $cash, 'price_bound' => (string) $bound];
                    $baseline = $this->baseline($portfolio, $session, $positions);
                    if ($baseline === null) {
                        throw new DecisionFailure('verified_session_baseline_unavailable', 'unavailable');
                    }
                } else {
                    $held = $positions->where('symbol', $signal->symbol)->where('sleeve', $execution->payload['decision_sleeve'])->first();
                    $quantity = Decimal::from($held?->quantity ?? '0')->minus(Decimal::from($held?->reserved_quantity ?? '0'))->rounded(0, RoundingMode::DOWN);
                    $baseline = null;
                }
                if ($quantity->compareTo(Decimal::from('0')) <= 0) {
                    return $record('no_order', 'whole_share_size_zero');
                }
                $evidence['session_baseline'] = $buy ? $baseline : ['kind' => 'not_required_for_exit'];
                $assumptions = new SimulationAssumptions(['fee_per_share' => '0', 'fee_bps' => '0', 'slippage_bps' => $settings['slippage_bps'],
                    'quote_max_age_seconds' => $settings['quote_max_age_seconds'], 'allow_fractional' => false, 'allow_fractional_limit' => false,
                    'cost_schedule' => $settings['cost_schedule'], 'session_date' => $session['date'], 'session_open_equity' => $buy ? $baseline['equity'] : null] +
                    ($buy ? [] : ['baseline_kind' => 'not_required_for_exit']));
                $order = app(OfflineSimulator::class)->submit($current, $actor, $execution->policy_application_id, $portfolio->ledger_version,
                    ['request_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'signal-paper-order:'.$signal->id)->toString(),
                        'symbol' => $signal->symbol, 'side' => $signal->side, 'sleeve' => $execution->payload['decision_sleeve'],
                        'type' => 'limit', 'quantity' => (string) $quantity, 'price_bound' => (string) $bound,
                        'expires_at' => CarbonImmutable::parse($session['closes_at'])->toIso8601String(),
                        'strategy_signal_id' => $signal->id, 'strategy_execution_policy_id' => $policy->id,
                        'rationale' => 'Deterministic '.$signal->rule_version.': '.$signal->reason], $assumptions, $marks, $execution->id);

                return $record($order->status === 'rejected' ? 'rejected' : 'order_created', $order->status === 'rejected' ? 'paper_order_risk_rejected' : null, $order);
            } catch (DecisionFailure $error) {
                return $record($error->outcome, $error->reason);
            }
        });
    }

    private function baseline(Portfolio $portfolio, array $session, $positions): ?array
    {
        $prior = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('side', 'BUY')->where('accepted_at', '>=', CarbonImmutable::parse($session['opens_at']))
            ->where('status', '!=', 'rejected')->orderBy('id')->get()->first(fn ($order) => isset($order->intent['strategy_signal_id']));
        if ($prior) {
            return ['equity' => $prior->assumptions['session_open_equity'], 'kind' => 'prior_verified_signal_order', 'order_id' => $prior->id];
        }
        if (app(PaperIncome::class)->receivable($portfolio)->compareTo(Decimal::from('0')) > 0 ||
            $positions->contains(fn ($p) => Decimal::from($p->quantity)->compareTo(Decimal::from('0')) > 0) ||
            CashEntry::where('paper_portfolio_id', $portfolio->id)->where('recorded_at', '>=', CarbonImmutable::parse($session['opens_at']))->where('kind', '!=', 'opening_balance')->exists()) {
            return null;
        }

        return ['equity' => $portfolio->cash, 'kind' => 'unchanged_cash_only_ledger'];
    }
}
