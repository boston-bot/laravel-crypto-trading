<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\Monitoring\BrokerOrderRead;
use App\Models\Monitoring\Holding;
use App\Models\Monitoring\MarketSession;
use App\Models\Monitoring\Run;
use App\Models\Monitoring\Snapshot;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Models\User;
use App\Services\Execution\LivePolicies;
use App\Services\Execution\LivePositionFacts;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Shared deterministic rules over explicit paper or live position facts. No network, orders, policy or ledger changes. */
final class StrategySignals
{
    public function tick(?int $account = null): int
    {
        if (! config('paper.signals_enabled')) {
            return 0;
        }
        $count = 0;
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        foreach (McpConnection::whereIn('user_id', $operators)->where('status', 'connected')->where('account_role', 'agentic_monitoring')
            ->whereNotNull('bound_account_id')->when($account !== null, fn ($q) => $q->whereKey($account))->get() as $connection) {
            if (RuleEligibilityPolicies::selected($connection)) {
                $count += $this->record($connection, $connection->user_id)->count();
            }
        }

        return $count;
    }

    public function recordLiveExits(McpConnection $connection, int $actor)
    {
        return $this->record($connection, $actor, null, 'live');
    }

    public function recordLive(McpConnection $connection, int $actor, array $symbols)
    {
        abort_unless(config('mcp.live_automation_enabled'), 409, 'Automatic execution is disabled.');

        return $this->record($connection, $actor, $symbols, 'live', true);
    }

    public function record(McpConnection $connection, int $actor, ?array $batchSymbols = null, string $positionSource = 'paper', bool $liveEntries = false)
    {
        abort_unless(in_array($positionSource, ['paper', 'live'], true), 422);
        $live = $positionSource === 'live';
        abort_unless(! $liveEntries || ($live && config('mcp.live_automation_enabled')), 409, 'Automatic execution is disabled.');
        abort_unless($live ? config('mcp.live_exit_signals_enabled') : config('paper.signals_enabled'), 409, 'Strategy signals are disabled.');

        return DB::transaction(function () use ($connection, $actor, $batchSymbols, $live, $positionSource, $liveEntries) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->bound_account_id &&
                $current->auth_generation === $connection->auth_generation && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account authorization changed.');
            $scope = AccountScope::key($current);
            $portfolio = Portfolio::where('mcp_connection_id', $current->id)->where('scope', $scope)->lockForUpdate()->firstOrFail();
            // Capture mutable portfolio state only after both locks; no historical-cutoff API is exposed.
            $cutoff = CarbonImmutable::now();
            $cycleAt = CarbonImmutable::createFromTimestampUTC(intdiv($cutoff->getTimestamp(), 300) * 300);
            $existing = StrategyCycle::where('mcp_connection_id', $current->id)->where('scope', $scope)->where('auth_generation', $current->auth_generation)
                ->where('rule_version', SignalRules::VERSION)->where('position_source', $positionSource)->where('cycle_at', $cycleAt->format('Y-m-d H:i:s.u'))->first();
            if ($existing && $batchSymbols === null) {
                return StrategySignal::where('cycle_id', $existing->id)->orderBy('symbol')->get();
            }
            $execution = ExecutionPolicies::current($portfolio);
            abort_unless($live || ($execution && RuleEligibilityPolicies::current($portfolio, $cutoff)), 409, 'Applied rule and execution settings are required.');
            $livePolicy = $live ? LivePolicies::current($scope) : null;
            abort_unless(! $live || $livePolicy, 409, 'An applied live policy is required.');
            $maxAge = $live ? $livePolicy->payload['quote_max_age_seconds'] : $execution->payload['quote_max_age_seconds'];
            $parameters = SignalParameters::current();
            $membership = $live && ! $liveEntries ? ['revision' => null] : app(StrategyWatchlists::class)->nominations($current, $cutoff);
            $symbols = $live ? app(LivePositionFacts::class)->symbols($current, $cutoff) : app(SymbolUniverse::class)->reads($current, $cutoff);
            if ($live && $liveEntries) {
                $symbols = array_values(array_unique([...$symbols, ...app(SymbolUniverse::class)->candidates($current, $cutoff)]));
                sort($symbols);
            }
            if ($batchSymbols !== null) {
                abort_if(array_diff($batchSymbols, $symbols), 409, 'Batch symbols are no longer in the selected universe.');
            }
            $selected = $batchSymbols === null ? $symbols : array_values(array_intersect($symbols, $batchSymbols));
            $session = $this->session($cutoff);
            $snapshot = Snapshot::where('mcp_connection_id', $current->id)->where('scope', $scope)->where('status', 'complete')
                ->whereIn('run_id', Run::where('mcp_connection_id', $current->id)->where('auth_generation', $current->auth_generation)->select('id'))
                ->where('published_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->where('retrieved_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->latest('publication_version')->first();
            $orderRead = BrokerOrderRead::where('mcp_connection_id', $current->id)->where('scope', $scope)->where('auth_generation', $current->auth_generation)
                ->where('recorded_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->latest('id')->first();
            $cycle = $existing ?? StrategyCycle::create(['mcp_connection_id' => $current->id, 'paper_portfolio_id' => $portfolio->id, 'scope' => $scope,
                'auth_generation' => $current->auth_generation, 'position_source' => $positionSource, 'actor_id' => $actor, 'rule_version' => SignalRules::VERSION,
                'cycle_at' => $cycleAt, 'cutoff' => $cutoff, 'recorded_at' => now(), 'payload' => ['symbols' => $symbols,
                    'ledger_version' => $live ? null : $portfolio->ledger_version, 'execution_version_id' => $live ? null : $execution?->id, 'session' => $session,
                    'snapshot_id' => $snapshot?->id, 'order_read_id' => $orderRead?->id, 'parameters' => $parameters,
                    'evaluation_mode' => $batchSymbols === null ? 'whole_universe' : 'incremental_batches', 'mode' => $live ? ($liveEntries ? 'live_strategy_signal_evidence' : 'live_exit_signal_evidence') : 'paper_signal_evidence']]);
            foreach ($selected as $symbol) {
                // The connection lock serializes overlapping batches and retries within this window.
                if (StrategySignal::where('cycle_id', $cycle->id)->where('symbol', $symbol)->exists()) {
                    continue;
                }
                $input = app(PaperMarketInputs::class)->latest($current, $symbol, $cutoff);
                $profile = app(SymbolProfiles::class)->latest($current, $symbol, $cutoff);
                $position = $live ? app(LivePositionFacts::class)->inspect($current, $symbol, $cutoff) : app(SignalPositionFacts::class)->inspect($portfolio, $symbol, $cutoff);
                $history = app(PaperMarketReferences::class)->verifiedHistory($current, $symbol, $cutoff);
                $benchmark = app(PaperMarketReferences::class)->verifiedHistory($current, $parameters['values']['benchmark_symbol'], $cutoff);
                $validProfile = $profile && $profile->status === 'complete' && $profile->profile_version === ProfileCalculator::VERSION &&
                    $profile->parameters_version === StrategyParameters::VERSION &&
                    ($profile->payload['evidence']['parameters_hash'] ?? null) === StrategyParameters::current()['hash'] && $history['read'] && $benchmark['read'] &&
                    $profile->history_read_id === $history['read_id'] && $profile->benchmark_read_id === $benchmark['read_id'] &&
                    $profile->session_date->toDateString() === ($session['previous_session'] ?? null) &&
                    $benchmark['read']->payload['facts']['last_session'] === ($session['previous_session'] ?? null);
                $report = ['profile_valid' => (bool) $validProfile, 'position' => $position, 'eligibility' => null, 'earnings' => null];
                $brokerHeld = $snapshot ? Holding::where('snapshot_id', $snapshot->id)->where('symbol', $symbol)->get()
                    ->contains(fn ($h) => Decimal::from($h->quantity)->compareTo(Decimal::from('0')) !== 0) : false;
                $quote = StoredStrategyQuote::read($input, $current, $cutoff, $maxAge);
                if ($live && (! $liveEntries || $position['held'])) {
                    $assessment = $this->liveExitAssessment($current, $symbol, $cutoff, $maxAge);
                    $result = $assessment['result'];
                    $report = $assessment['report'];
                } elseif ($session['status'] !== 'regular') {
                    $result = SignalRules::result($session['status'] === 'unknown' ? 'unavailable' : 'wait', 'session_'.$session['status']);
                } elseif ($position['held']) {
                    if ($quote === null) {
                        $result = SignalRules::result('unavailable', 'fresh_scoped_quote_unavailable');
                    } elseif ($position['context'] === null) {
                        $result = SignalRules::result('unavailable', $position['reason']);
                    } else {
                        $context = $this->exitContext($portfolio, $symbol, $position['context'], $validProfile ? $history['read']->payload['bars'] : [], $cutoff);
                        $report['position']['context'] = $context;
                        $earnings = config('robinhood_mcp.earnings_enabled') ? app(EarningsCalendar::class)->status($current, $symbol, $cutoff, 2) : ['status' => 'unavailable'];
                        $report['earnings'] = $earnings;
                        // Unknown corporate actions can invalidate all price levels. Never compare an unadjusted stop to split-adjusted prices.
                        $clearance = app(CorporateActionClearance::class)->inspect($input, $cutoff, $maxAge);
                        $report['corporate_actions'] = $clearance;
                        $result = $clearance['clear'] !== true ? SignalRules::result('unavailable', 'exit_corporate_actions_unverified') :
                            app(SignalRules::class)->exit($quote['bid'], $context, $validProfile ? $profile->payload : null, $earnings, $context['parameters']);
                    }
                } elseif ($brokerHeld) {
                    $result = SignalRules::result('unavailable', 'broker_position_not_managed_by_strategy');
                } else {
                    $eligibility = app(RuleEligibility::class)->inspect($current, $symbol, $cutoff);
                    $report['eligibility'] = $eligibility;
                    $earnings = config('robinhood_mcp.earnings_enabled') ? app(EarningsCalendar::class)->status($current, $symbol, $cutoff, $parameters['values']['earnings_blackout_sessions']) : ['status' => 'unavailable'];
                    $report['earnings'] = $earnings;
                    $open = ! $live && SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('symbol', $symbol)->whereIn('status', ['open', 'partial'])->exists();
                    $cooldown = $position['last_exit_at'] === null ? null : $this->sessionsSince($position['last_exit_at'], $cutoff);
                    $result = match (true) {
                        ! config('mcp.external_order_detection_enabled') => SignalRules::result('unavailable', 'external_order_detection_disabled'),
                        $eligibility['status'] !== 'eligible' => SignalRules::result('unavailable', 'eligibility_'.$eligibility['status']),
                        ! $snapshot || $snapshot->retrieved_at->lte($cutoff->subMinutes(5)) => SignalRules::result('unavailable', 'broker_positions_unverified'),
                        ! $live && ! $execution->payload['allow_entries'] => SignalRules::result('wait', 'entries_disabled_by_policy'),
                        $quote === null => SignalRules::result('unavailable', 'fresh_scoped_quote_unavailable'),
                        ! $validProfile => SignalRules::result('unavailable', 'current_profile_unavailable'),
                        $open => SignalRules::result('wait', 'open_order_exists'),
                        $position['last_exit_at'] !== null && $cooldown === null => SignalRules::result('unavailable', 'cooldown_calendar_unknown'),
                        $position['last_exit_at'] !== null && $cooldown < $parameters['values']['cooldown_sessions'] => SignalRules::result('wait', 'reentry_cooldown'),
                        $cutoff->lt(CarbonImmutable::parse($session['opens_at'])->addMinutes($parameters['values']['entry_delay_minutes'])) => SignalRules::result('wait', 'opening_entry_delay'),
                        $earnings['status'] !== 'pass' => SignalRules::result($earnings['status'] === 'blocked' ? 'wait' : 'unavailable', $earnings['status'] === 'blocked' ? 'earnings_blackout' : 'earnings_date_unknown'),
                        $profile->payload['market']['filter'] !== 'on' => SignalRules::result('wait', 'market_filter_off'),
                        default => app(SignalRules::class)->entry($profile->payload, $quote['ask'], $parameters['values']),
                    };
                    $report['cooldown_elapsed_sessions'] = $cooldown;
                }

                $usedParameters = $live && isset($report['position']['context']['parameters']) ? ['version' => $report['position']['context']['parameters_version'],
                    'values' => $report['position']['context']['parameters']] : $parameters;
                $evidence = ['rule_version' => SignalRules::VERSION, 'parameters' => $usedParameters, 'cutoff' => $cutoff->toISOString(),
                    'cycle_id' => $cycle->id, 'auth_generation' => $current->auth_generation, 'scope' => $scope, 'ledger_version' => $live ? null : $portfolio->ledger_version,
                    'execution_version_id' => $live ? null : $execution?->id, 'watchlist_revision_id' => $membership['revision']?->id,
                    'profile_id' => $profile?->id, 'profile_fingerprint' => $profile?->input_fingerprint, 'market_input_id' => $input?->id,
                    'market_input_times' => $input ? ['source_at' => $input->source_at?->toISOString(), 'retrieved_at' => $input->retrieved_at->toISOString(), 'recorded_at' => $input->recorded_at->toISOString()] : null,
                    'quote' => $quote, 'session' => $session, 'snapshot_id' => $snapshot?->id, 'order_read_id' => $orderRead?->id, 'report' => $report];
                StrategySignal::create(['mcp_connection_id' => $current->id, 'paper_portfolio_id' => $portfolio->id, 'cycle_id' => $cycle->id,
                    'symbol' => $symbol, 'profile_id' => $profile?->id, 'watchlist_revision_id' => $membership['revision']?->id, 'market_input_id' => $input?->id,
                    'status' => $result['status'], 'side' => $result['side'], 'reason' => $result['reason'], 'rule_version' => SignalRules::VERSION,
                    'position_source' => $positionSource, 'parameters_version' => $usedParameters['version'], 'input_fingerprint' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
                    'payload' => ['evidence' => $evidence, 'parameters' => $usedParameters, 'result' => $result, 'mode' => $live ? ($liveEntries ? 'live_strategy_signal_evidence' : 'live_exit_signal_evidence') : 'paper_signal_evidence', 'broker_executable' => false],
                    'cutoff' => $cutoff, 'recorded_at' => now()]);
            }

            return StrategySignal::where('cycle_id', $cycle->id)->whereIn('symbol', $selected)->orderBy('symbol')->get();
        });
    }

    /** The shared exit rules, re-evaluated from live fills and current stored evidence. No signal or ledger writes. */
    public function liveExitAssessment(McpConnection $connection, string $symbol, CarbonImmutable $cutoff, int $maxAge): array
    {
        $position = app(LivePositionFacts::class)->inspect($connection, $symbol, $cutoff);
        $report = ['position' => $position, 'earnings' => null, 'corporate_actions' => null, 'profile_valid' => false];
        $deny = fn ($reason) => ['result' => SignalRules::result('unavailable', $reason), 'report' => $report];
        $session = $this->session($cutoff);
        if ($session['status'] !== 'regular') {
            return $deny('session_'.$session['status']);
        }
        if (! $position['held'] || ! $position['context']) {
            return $deny($position['reason'] ?? 'live_position_absent');
        }
        $input = app(PaperMarketInputs::class)->latest($connection, $symbol, $cutoff);
        $quote = StoredStrategyQuote::read($input, $connection, $cutoff, $maxAge);
        if (! $quote) {
            return $deny('fresh_scoped_quote_unavailable');
        }
        $profile = app(SymbolProfiles::class)->latest($connection, $symbol, $cutoff);
        $history = app(PaperMarketReferences::class)->verifiedHistory($connection, $symbol, $cutoff);
        $benchmark = app(PaperMarketReferences::class)->verifiedHistory($connection, SignalParameters::current()['values']['benchmark_symbol'], $cutoff);
        $validProfile = $profile && $profile->status === 'complete' && $profile->profile_version === ProfileCalculator::VERSION &&
            $profile->parameters_version === StrategyParameters::VERSION && ($profile->payload['evidence']['parameters_hash'] ?? null) === StrategyParameters::current()['hash'] &&
            $history['read'] && $benchmark['read'] && $profile->history_read_id === $history['read_id'] && $profile->benchmark_read_id === $benchmark['read_id'] &&
            $profile->session_date->toDateString() === ($session['previous_session'] ?? null) &&
            $benchmark['read']->payload['facts']['last_session'] === ($session['previous_session'] ?? null);
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->firstOrFail();
        $context = $this->exitContext($portfolio, $symbol, $position['context'], $validProfile ? $history['read']->payload['bars'] : [], $cutoff, 'live');
        $report['position']['context'] = $context;
        $report['profile_valid'] = (bool) $validProfile;
        $earnings = config('robinhood_mcp.earnings_enabled') ? app(EarningsCalendar::class)->status($connection, $symbol, $cutoff, 2) : ['status' => 'unavailable'];
        $clearance = app(CorporateActionClearance::class)->inspect($input, $cutoff, $maxAge);
        $report['earnings'] = $earnings;
        $report['corporate_actions'] = $clearance;
        $result = $clearance['clear'] !== true ? SignalRules::result('unavailable', 'exit_corporate_actions_unverified') :
            app(SignalRules::class)->exit($quote['bid'], $context, $validProfile ? $profile->payload : null, $earnings, $context['parameters']);

        return ['result' => $result, 'report' => $report];
    }

    private function session(CarbonImmutable $cutoff): array
    {
        $date = $cutoff->setTimezone('America/New_York')->toDateString();
        $session = MarketSession::where('exchange', 'XNYS')->where('session_date', $date)->where('retrieved_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))
            ->where('retrieved_at', '>=', $cutoff->subDays(7)->format('Y-m-d H:i:s.u'))->first();
        if (! $session) {
            return ['status' => 'unknown'];
        }
        $status = $session->status === 'holiday' ? 'holiday' : ($cutoff->lt($session->opens_at) ? 'before_open' : ($cutoff->gte($session->closes_at) ? 'after_close' : 'regular'));
        $previous = null;
        for ($day = CarbonImmutable::parse($date)->subDay(); $day->gte(CarbonImmutable::parse($date)->subDays(7)); $day = $day->subDay()) {
            $prior = MarketSession::where('exchange', 'XNYS')->where('session_date', $day->toDateString())->where('retrieved_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->first();
            if (! $prior) {
                break;
            }
            if ($prior->status !== 'holiday') {
                $previous = $day->toDateString();
                break;
            }
        }

        return ['status' => $status, 'id' => $session->id, 'retrieved_at' => $session->retrieved_at->toISOString(),
            'opens_at' => $session->opens_at?->toISOString(), 'closes_at' => $session->closes_at?->toISOString(), 'previous_session' => $previous];
    }

    public function sessionsSince(string $exitAt, CarbonImmutable $cutoff): ?int
    {
        $day = CarbonImmutable::parse($exitAt)->setTimezone('America/New_York')->startOfDay()->addDay();
        $end = $cutoff->setTimezone('America/New_York')->startOfDay();
        $count = 0;
        for (; $day->lte($end); $day = $day->addDay()) {
            $session = MarketSession::where('exchange', 'XNYS')->where('session_date', $day->toDateString())->where('retrieved_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->first();
            if (! $session) {
                return null;
            }
            $count += $session->status !== 'holiday' ? 1 : 0;
        }

        return $count;
    }

    private function exitContext(Portfolio $portfolio, string $symbol, array $context, array $bars, CarbonImmutable $cutoff, string $positionSource = 'paper'): array
    {
        $date = CarbonImmutable::parse($context['entry_at'])->setTimezone('America/New_York')->toDateString();
        $trail = Decimal::from($context['initial_stop']);
        $progressed = false;
        $latched = null;
        $previous = StrategySignal::where('position_source', $positionSource)->where('symbol', $symbol)
            ->when($positionSource === 'paper', fn ($q) => $q->where('paper_portfolio_id', $portfolio->id),
                fn ($q) => $q->whereIn('cycle_id', StrategyCycle::where('scope', $portfolio->scope)->where('position_source', 'live')->select('id')))
            ->where('recorded_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->orderByDesc('id')->get();
        foreach ($previous as $row) {
            $old = $row->payload['evidence']['report']['position']['context'] ?? null;
            if (($old['entry_order_id'] ?? null) === $context['entry_order_id']) {
                if ($row->status === 'signal' && $row->side === 'SELL') {
                    $latched = $row->id;
                }
                $candidate = Decimal::from($old['trailing_stop'] ?? $context['initial_stop']);
                $trail = $candidate->compareTo($trail) > 0 ? $candidate : $trail;
                $progressed = $progressed || ($old['progressed'] ?? false);
            }
        }
        $sessions = null;
        if ($bars !== [] && $bars[0][0] <= $date) {
            $sessions = 0;
            foreach ($bars as $bar) {
                if ($bar[0] < $date) {
                    continue;
                }
                $sessions++;
                $close = Decimal::from($bar[4]);
                // Pin ATR to the opening signal for the episode. Stop levels can only rise, even after history revisions.
                $candidate = $close->minus(Decimal::from($context['entry_atr'])->multipliedBy(Decimal::from($context['parameters']['trailing_stop_atr'])));
                $trail = $candidate->compareTo($trail) > 0 ? $candidate : $trail;
                $progressed = $progressed || $close->compareTo(Decimal::from($context['entry_price'])->plus(Decimal::from($context['entry_atr']))) >= 0;
            }
        }

        return $context + ['trailing_stop' => (string) $trail, 'sessions_held' => $sessions, 'progressed' => $progressed, 'latched_exit_signal_id' => $latched];
    }
}
