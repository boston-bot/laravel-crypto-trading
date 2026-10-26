<?php

namespace App\Services\Execution;

use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Models\Monitoring\Holding;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Run;
use App\Models\Monitoring\Snapshot;
use App\Models\PaperTrading\StrategySignal;
use App\Services\Broker\Agentic\RobinhoodOrderContract;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\ExternalOrders;
use App\Services\Monitoring\MarketCalendar;
use App\Services\PaperTrading\BrokerSimulationQuote;
use App\Services\PaperTrading\DecisionFailure;
use App\Services\PaperTrading\EarningsCalendar;
use App\Services\PaperTrading\PaperMarketInputs;
use App\Services\PaperTrading\PortfolioEntryLimits;
use App\Services\PaperTrading\RiskSizer;
use App\Services\PaperTrading\RuleEligibility;
use App\Services\PaperTrading\SignalOrderPrice;
use App\Services\PaperTrading\SignalParameters;
use App\Services\PaperTrading\SignalRules;
use App\Services\PaperTrading\SymbolProfiles;
use App\Services\PaperTrading\TradingCosts;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Local-only live checks at the last boundary before a claim. Every input is a stored broker-confirmed read or an
 * applied owner setting; paper cash, paper positions and model text never authorize a live order. The caller holds
 * the connection and execution-account locks. A previous pass is never an exemption: the claim reruns everything.
 */
class SubmissionChecks
{
    public function reason(McpConnection $connection, OrderIntent $intent): ?string
    {
        $signal = StrategySignal::whereKey($intent->signal_id)->where('mcp_connection_id', $connection->id)->first();
        $payload = $intent->payload;
        if (! $signal || $signal->symbol !== $payload['symbol'] || $signal->side !== $payload['side'] || $signal->input_fingerprint !== $payload['signal_input_fingerprint']) {
            return 'signal_provenance_invalid';
        }
        $plan = $this->assess($connection, $signal, $payload['policy_fingerprint']);
        if ($plan['reason'] !== null) {
            return $plan['reason'];
        }
        $planned = $plan['draft'];
        if ($payload['type'] !== $planned['type'] || Decimal::from($payload['price_bound'])->compareTo(Decimal::from($planned['price_bound'])) !== 0) {
            return 'live_order_terms_changed';
        }
        $quantity = Decimal::from($payload['quantity']);
        if ($quantity->compareTo($quantity->rounded($plan['scale'], RoundingMode::DOWN)) !== 0 || $quantity->compareTo(Decimal::from($planned['quantity'])) > 0) {
            return 'live_size_exceeded';
        }
        if ($plan['scale'] > 0 && $quantity->multipliedBy(Decimal::from($plan['reference_price']))->compareTo(Decimal::from(RobinhoodOrderContract::FRACTIONAL_MIN_NOTIONAL)) < 0) {
            return 'below_fractional_minimum_order_value';
        }

        return null;
    }

    /**
     * Proposes the largest admissible draft from the same facts the claim rechecks. The draft is unapproved evidence
     * for ExecutionJournal::stage; quantities are recomputed, never trusted, at submission.
     *
     * @return array{reason: ?string, draft: ?array, scale: int, reference_price: ?string, evidence: array}
     */
    public function plan(McpConnection $connection, StrategySignal $signal): array
    {
        return $this->assess($connection, $signal, null);
    }

    private function assess(McpConnection $c, StrategySignal $signal, ?string $fingerprint): array
    {
        $deny = fn (string $reason, array $evidence = []) => ['reason' => $reason, 'draft' => null, 'scale' => 0, 'reference_price' => null, 'evidence' => $evidence];
        $scope = AccountScope::key($c);
        $controls = ExecutionControls::state($scope);
        if ($controls['emergency_disabled']) {
            return $deny('execution_emergency_disabled');
        }
        if (Incident::where('scope', $scope)->whereIn('incident_key', [BrokerOutcomes::MISMATCH, BrokerOutcomes::MISSING])->where('state', '!=', 'resolved')->exists()) {
            return $deny('execution_reconciliation_review_required');
        }
        $policy = LivePolicies::current($scope);
        if (! $policy) {
            return $deny('live_policy_missing');
        }
        if ($fingerprint !== null && ! hash_equals($policy->fingerprint, $fingerprint)) {
            return $deny('live_policy_changed');
        }
        $s = $policy->payload;
        $now = CarbonImmutable::now();
        if (! TradingCosts::valid($s['cost_schedule'], $now)) {
            return $deny('cost_schedule_expired_or_unreviewed');
        }
        if ($signal->status !== 'signal' || ! in_array($signal->side, ['BUY', 'SELL'], true) || $signal->rule_version !== SignalRules::VERSION) {
            return $deny('signal_provenance_invalid');
        }
        $session = app(MarketCalendar::class)->at($now);
        // Robinhood fractional orders are regular-hours only; no new live order in the last five minutes of a session.
        if ($session['status'] !== 'regular' || CarbonImmutable::parse($session['closes_at'])->subMinutes(5)->lte($now)) {
            return $deny('outside_verified_session');
        }
        $snapshot = $this->snapshot($c, $now);
        $obs = $snapshot?->observations ?? [];
        if (! $snapshot || ! isset($obs['equity'], $obs['buying_power'], $obs['equity_exposure'])) {
            return $deny('broker_account_unverified');
        }
        $held = Decimal::from('0');
        $occupied = [];
        foreach (Holding::where('snapshot_id', $snapshot->id)->get() as $holding) {
            $quantity = Decimal::from((string) $holding->quantity);
            if ($quantity->compareTo(Decimal::from('0')) > 0) {
                $occupied[$holding->symbol] = true;
                if ($holding->symbol === $signal->symbol) {
                    $held = $held->plus($quantity);
                }
            }
        }
        $input = app(PaperMarketInputs::class)->latest($c, $signal->symbol, $now);
        if (! $input) {
            return $deny('quote_unavailable');
        }
        try {
            $quote = BrokerSimulationQuote::observe($c, $input->id, $now, $s['quote_max_age_seconds']);
            if ($stale = $quote->reason($now, $s['quote_max_age_seconds'])) {
                return $deny($stale);
            }
            $quote = $quote->data;
        } catch (DecisionFailure $failure) {
            return $deny($failure->reason);
        }
        $type = $s['order_type'];
        $scale = SignalOrderPrice::scale($type, $s, $quote);
        $evidence = ['policy_id' => $policy->id, 'policy_version' => $policy->version, 'snapshot_id' => $snapshot->id, 'market_input_id' => $input->id];
        if ($signal->side === 'SELL') {
            // Exits ignore buy pauses, watchlists and entry incidents, but sell only broker-held shares this app's
            // broker-confirmed fills put there: never the owner's or another agent's holdings, never short.
            $attributed = $this->attributed($scope, $signal->symbol);
            $available = ($attributed->compareTo($held) < 0 ? $attributed : $held)->rounded($scale, RoundingMode::DOWN);
            if ($available->compareTo(Decimal::from('0')) <= 0) {
                return $deny('live_position_unattributed_or_absent', $evidence);
            }
            $bound = $this->bound($type, 'SELL', $quote, null, $s);

            return ['reason' => null, 'draft' => ['type' => $type, 'quantity' => (string) $available, 'price_bound' => (string) $bound,
                'policy_fingerprint' => $policy->fingerprint], 'scale' => $scale, 'reference_price' => $quote['bid'], 'evidence' => $evidence + ['attributed' => (string) $attributed]];
        }
        if ($controls['buy_paused']) {
            return $deny('buy_paused', $evidence);
        }
        if (! config('mcp.external_order_detection_enabled')) {
            return $deny('external_order_detection_disabled', $evidence);
        }
        if ($reason = app(ExternalOrders::class)->entryReason($c)) {
            return $deny($reason, $evidence);
        }
        $claimed = DB::table('mcp_execution_dispatches as d')->join('mcp_execution_intents as i', 'i.id', '=', 'd.intent_id')
            ->join('mcp_execution_accounts as a', 'a.id', '=', 'i.execution_account_id')->where('a.scope', $scope)
            ->where('d.claimed_at', '>=', CarbonImmutable::parse($session['opens_at'])->utc()->format('Y-m-d H:i:s.u'))->count();
        if ($claimed >= $s['max_orders_per_session']) {
            return $deny('live_session_order_limit', $evidence);
        }
        $eligibility = app(RuleEligibility::class)->inspect($c, $signal->symbol, $now);
        if ($eligibility['status'] !== 'eligible') {
            return $deny('symbol_ineligible_or_unknown', $evidence + ['eligibility' => $eligibility['checks'] ?? $eligibility['reason'] ?? null]);
        }
        $profile = app(SymbolProfiles::class)->latest($c, $signal->symbol, $now);
        if (! $profile || $profile->id !== $signal->profile_id || $profile->status !== 'complete' || ($profile->payload['market']['filter'] ?? null) !== 'on' ||
            ! isset($profile->payload['levels']['max_entry'])) {
            return $deny('entry_profile_changed_or_blocked', $evidence);
        }
        $earnings = config('robinhood_mcp.earnings_enabled') ? app(EarningsCalendar::class)->status($c, $signal->symbol, $now, SignalParameters::current()['values']['earnings_blackout_sessions']) : ['status' => 'unavailable'];
        if ($earnings['status'] !== 'pass') {
            return $deny('entry_earnings_blocked_or_unknown', $evidence);
        }
        $parameters = $signal->payload['parameters']['values'] ?? null;
        $atr = $signal->payload['result']['facts']['entry_atr'] ?? null;
        if (! is_array($parameters) || ! is_string($atr) || ! isset($parameters['initial_stop_atr'])) {
            return $deny('signal_risk_inputs_missing', $evidence);
        }
        if (app(SignalRules::class)->entry($profile->payload, $quote['ask'], $parameters)['status'] !== 'signal') {
            return $deny('entry_trigger_no_longer_valid', $evidence);
        }
        $maxEntry = $profile->payload['levels']['max_entry'];
        if (Decimal::from($quote['ask'])->compareTo(Decimal::from($maxEntry)) > 0) {
            return $deny('market_price_guard_failed', $evidence);
        }
        if ($held->compareTo(Decimal::from('0')) > 0) {
            return $deny('broker_symbol_already_held', $evidence);
        }
        $opening = $this->opening($c, $session);
        if ($reason = PortfolioEntryLimits::reason($obs['equity'], $opening, count($occupied), $s)) {
            return $deny($reason === 'portfolio_valuation_unavailable' ? 'live_session_baseline_unavailable' : $reason, $evidence);
        }
        $bound = $this->bound($type, 'BUY', $quote, $maxEntry, $s);
        $stop = $bound->minus(Decimal::from($atr)->multipliedBy(Decimal::from((string) $parameters['initial_stop_atr'])));
        $sleeve = Decimal::from($s['sleeve_capital']);
        $equity = Decimal::from($obs['equity']);
        // The account is dedicated to the agent: every broker-confirmed holding consumes sleeve capacity.
        $remaining = $sleeve->minus(Decimal::from($obs['equity_exposure']));
        $cap = Decimal::from($s['max_order_notional']);
        $cap = $remaining->compareTo($cap) < 0 ? $remaining : $cap;
        $size = app(RiskSizer::class)->calculate($equity->compareTo($sleeve) < 0 ? $equity : $sleeve, Decimal::from($obs['buying_power']),
            $bound, $stop, $s, $cap, $scale);
        if (Decimal::from($size['quantity'])->compareTo(Decimal::from('0')) <= 0) {
            return $deny('live_'.($size['reason'] ?? 'size_zero'), $evidence + ['sizing' => $size]);
        }

        return ['reason' => null, 'draft' => ['type' => $type, 'quantity' => $size['quantity'], 'price_bound' => (string) $bound,
            'policy_fingerprint' => $policy->fingerprint], 'scale' => $scale, 'reference_price' => (string) $bound, 'evidence' => $evidence + ['sizing' => $size]];
    }

    /** Net broker-confirmed app fills for a symbol on this account: buys minus sells. */
    public function attributed(string $scope, string $symbol): Decimal
    {
        $net = Decimal::from('0');
        $fills = DB::table('mcp_execution_fills as f')->join('mcp_execution_accounts as a', 'a.id', '=', 'f.execution_account_id')
            ->where('a.scope', $scope)->where('f.symbol', $symbol)->get(['f.side', 'f.quantity']);
        foreach ($fills as $fill) {
            $quantity = Decimal::from((string) $fill->quantity);
            $net = $fill->side === 'BUY' ? $net->plus($quantity) : $net->minus($quantity);
        }

        return $net;
    }

    /** Robinhood ticks: limit prices in cents, collars at the intent's six decimals. Rounding always tightens the order. */
    private function bound(string $type, string $side, array $quote, ?string $maxEntry, array $settings): Decimal
    {
        return SignalOrderPrice::bound($type, $side, $quote, $maxEntry, $settings)
            ->rounded($type === 'limit' ? 2 : 6, $side === 'BUY' ? RoundingMode::DOWN : RoundingMode::UP);
    }

    private function snapshot(McpConnection $c, CarbonImmutable $now): ?Snapshot
    {
        $snapshot = Snapshot::where('mcp_connection_id', $c->id)->where('scope', AccountScope::key($c))->where('status', 'complete')
            ->whereIn('run_id', Run::where('mcp_connection_id', $c->id)->where('auth_generation', $c->auth_generation)->select('id'))->latest('publication_version')->first();

        return $snapshot && ! $snapshot->retrieved_at->isFuture() && $snapshot->retrieved_at->gt($now->subMinutes(5)) ? $snapshot : null;
    }

    /** Broker-confirmed session baseline: the first complete snapshot within 30 minutes of the open. Missing is unknown. */
    private function opening(McpConnection $c, array $session): ?string
    {
        $open = CarbonImmutable::parse($session['opens_at']);
        $snapshot = Snapshot::where('mcp_connection_id', $c->id)->where('scope', AccountScope::key($c))->where('status', 'complete')
            ->whereIn('run_id', Run::where('mcp_connection_id', $c->id)->where('auth_generation', $c->auth_generation)->select('id'))
            ->whereBetween('retrieved_at', [$open->utc()->format('Y-m-d H:i:s.u'), $open->addMinutes(30)->utc()->format('Y-m-d H:i:s.u')])
            ->oldest('retrieved_at')->first();

        return $snapshot?->observations['equity'] ?? null;
    }
}
