<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\DecisionRun;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\OrderHandoff;
use App\Models\PaperTrading\PolicyApplication;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** A separate, gated paper-only boundary. It receives a recorded run ID, never executable model parameters. */
final class PaperOrderHandoff
{
    public function __construct(private PaperMarketInputs $inputs, private OfflineSimulator $simulator, private MarketCalendar $calendar) {}

    public function process(McpConnection $connection, int $actor, string $runId): Collection
    {
        abort_unless(config('paper.handoff_enabled'), 409, 'Paper proposal handoff is disabled.');

        return DB::transaction(function () use ($connection, $actor, $runId) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account binding changed.');
            $run = DecisionRun::whereKey($runId)->where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->lockForUpdate()->firstOrFail();
            $portfolio = Portfolio::whereKey($run->paper_portfolio_id)->where('mcp_connection_id', $current->id)->where('scope', $run->scope)->lockForUpdate()->firstOrFail();
            $prior = OrderHandoff::where('decision_run_id', $run->id)->orderBy('id')->get();
            if ($prior->isNotEmpty()) {
                return $prior; // A completed logical handoff cannot become another order after expiry/restart.
            }
            $execution = ExecutionPolicies::current($portfolio);
            $reason = match (true) {
                $current->status !== 'connected' || $current->auth_generation !== $run->auth_generation => 'account_authorization_changed',
                ! in_array($run->status, ['proposed', 'wait'], true) => 'decision_not_accepted',
                $run->expires_at->lte(now()) => 'proposal_expired',
                $portfolio->ledger_version !== $run->ledger_version => 'paper_ledger_changed',
                ! $execution || $execution->id !== $run->execution_version_id || $execution->policy_application_id !== $run->policy_application_id => 'execution_settings_missing_or_changed',
                default => null,
            };
            if ($reason) {
                return collect([$this->record($run, $actor, '*', 'unavailable', $reason, [], $execution?->id)]);
            }
            $policy = PolicyApplication::findOrFail($run->policy_application_id);
            $context = $run->request_snapshot['input'] ?? [];
            try {
                $validated = DecisionSchema::validate($run->result['raw_output'] ?? null,
                    ['subjects' => $context['subjects'] ?? [], 'positions' => $context['paper_portfolio']['positions'] ?? null,
                        'source_map' => $run->request_snapshot['source_map'] ?? []],
                    $policy->snapshot['rules'], CarbonImmutable::parse($context['requested_at'] ?? 'invalid'), $run->expires_at, $execution->payload['quote_max_age_seconds']);
            } catch (DecisionFailure|\InvalidArgumentException $failure) {
                return collect([$this->record($run, $actor, '*', 'rejected', 'recorded_proposal_invalid', [], $execution->id)]);
            }
            if ($validated['decisions'] === []) {
                return collect([$this->record($run, $actor, '*', 'unavailable', 'no_decisions', [], $execution->id)]);
            }
            $rules = $execution->payload;
            $results = collect();
            foreach ($validated['decisions'] as $decision) {
                $symbol = $decision['symbol'];
                if (in_array($decision['action'], ['WAIT', 'HOLD'], true)) {
                    $results->push($this->record($run, $actor, $symbol, 'no_order', strtolower($decision['action']), ['proposal' => $decision], $execution->id));

                    continue;
                }
                $evidence = ['proposal' => $decision, 'policy_id' => $policy->id, 'execution_version_id' => $execution->id, 'market_input_ids' => []];
                try {
                    if (! in_array($symbol, $rules['allowed_symbols'], true) || ! $rules['allow_ai_sizing'] ||
                        ! $rules[$decision['action'] === 'BUY' ? 'allow_entries' : 'allow_exits']) {
                        throw new DecisionFailure('owner_execution_permission_denied');
                    }
                    if (SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('symbol', $symbol)->whereIn('status', ['open', 'partial'])->exists()) {
                        throw new DecisionFailure('symbol_has_open_order');
                    }
                    if (config('paper.corporate_actions_enabled')) {
                        $latest = $this->inputs->latest($current, $symbol, CarbonImmutable::now());
                        if ($latest && isset($latest->payload['corporate_action_reads']['alpaca'])) {
                            $beforeActions = $portfolio->fresh()->ledger_version;
                            app(PaperCorporateActions::class)->process($current, $actor, $latest->id);
                            if ($portfolio->fresh()->ledger_version !== $beforeActions) {
                                throw new DecisionFailure('corporate_action_changed_ledger', 'unavailable');
                            }
                        }
                    }
                    $requested = CarbonImmutable::parse($context['requested_at']);
                    $original = $context['subjects'][$symbol]['input_id'] ?? null;
                    $originalInput = MarketInput::whereKey($original)->where('mcp_connection_id', $current->id)->where('scope', $run->scope)->where('symbol', $symbol)->first();
                    if (! $originalInput || $this->inputs->executionReason($originalInput, $requested, $rules['quote_max_age_seconds'])) {
                        throw new DecisionFailure('proposal_market_provenance_unverified');
                    }
                    if ($originalInput->origin === 'broker_read') {
                        if (! config('paper.real_data_fills_enabled')) {
                            throw new DecisionFailure('real_data_paper_fills_disabled', 'unavailable');
                        }
                        BrokerSimulationQuote::fromStored($current, $originalInput->id, $requested, $rules['quote_max_age_seconds']);
                    }
                    $positions = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->get();
                    $symbols = array_unique([$symbol, ...$positions->filter(fn ($p) => Decimal::from($p->quantity)->compareTo(Decimal::from('0')) > 0)->pluck('symbol')->all()]);
                    $marks = [];
                    foreach ($symbols as $needed) {
                        $input = $this->inputs->latest($current, $needed, CarbonImmutable::now());
                        if ($reason = $this->inputs->executionReason($input, CarbonImmutable::now(), $rules['quote_max_age_seconds'])) {
                            throw new DecisionFailure($reason, 'unavailable');
                        }
                        $evidence['market_input_ids'][$needed] = $input->id;
                        if ($input->origin !== $originalInput->origin) {
                            throw new DecisionFailure('market_input_origin_changed', 'unavailable');
                        }
                        if ($input->origin === 'broker_read') {
                            $marks[$needed] = BrokerSimulationQuote::fromStored($current, $input->id, CarbonImmutable::now(), $rules['quote_max_age_seconds']);
                        } else {
                            $quote = $input->payload['quote'];
                            unset($quote['bid_as_of'], $quote['ask_as_of']);
                            $marks[$needed] = new SimulationQuote($quote);
                        }
                    }
                    $session = $this->calendar->at(CarbonImmutable::now());
                    if ($session['status'] !== 'regular') {
                        throw new DecisionFailure('outside_verified_session', 'unavailable');
                    }
                    $baseline = $this->baseline($portfolio, $session, $positions);
                    if ($baseline === null) {
                        throw new DecisionFailure('verified_session_baseline_unavailable', 'unavailable');
                    }
                    $evidence['session_baseline'] = $baseline;
                    $equity = Decimal::from($portfolio->fresh()->cash)->plus(app(PaperIncome::class)->receivable($portfolio));
                    foreach ($positions as $position) {
                        if (isset($marks[$position->symbol])) {
                            $equity = $equity->plus(Decimal::from($position->quantity)->multipliedBy(Decimal::from($marks[$position->symbol]->data['bid'])));
                        }
                    }
                    $buy = $decision['action'] === 'BUY';
                    $offset = Decimal::from($rules['limit_offset_bps'])->dividedBy(Decimal::from('10000'), 12);
                    $bound = Decimal::from($marks[$symbol]->data[$buy ? 'ask' : 'bid'])->multipliedBy(Decimal::from('1')->plus($offset->multipliedBy(Decimal::from($buy ? '1' : '-1'))))
                        ->rounded(8, $buy ? RoundingMode::CEILING : RoundingMode::FLOOR);
                    if ($bound->compareTo(Decimal::from('0')) <= 0) {
                        throw new DecisionFailure('price_bound_below_supported_precision');
                    }
                    $quantityScale = $rules['allow_fractional'] && $rules['allow_fractional_limit'] && $marks[$symbol]->data['fractional'] === true ? 8 : 0;
                    $target = $equity->multipliedBy(Decimal::from($decision['target_weight_pct']))->dividedBy(Decimal::from('100'), 18)
                        ->dividedBy($bound, $quantityScale, RoundingMode::DOWN);
                    $held = Decimal::from($positions->where('symbol', $symbol)->where('sleeve', $rules['decision_sleeve'])->first()?->quantity ?? '0');
                    $quantity = ($buy ? $target->minus($held) : $held->minus($target))->rounded($quantityScale, RoundingMode::DOWN);
                    if ($quantity->compareTo(Decimal::from('0')) <= 0) {
                        $results->push($this->record($run, $actor, $symbol, 'no_order', 'no_quantity_in_proposed_direction', $evidence, $execution->id));

                        continue;
                    }
                    $until = CarbonImmutable::parse($decision['expires_at'])->min($run->expires_at)->min(CarbonImmutable::parse($session['closes_at']));
                    $assumptions = new SimulationAssumptions(array_intersect_key($rules, array_flip(['fee_per_share', 'fee_bps', 'slippage_bps', 'allow_fractional', 'allow_fractional_limit', 'quote_max_age_seconds'])) +
                        ['session_open_equity' => $baseline['equity'], 'session_date' => $session['date']]);
                    $order = $this->simulator->submit($current, $actor, $policy->id, $portfolio->fresh()->ledger_version,
                        ['request_id' => (string) Uuid::uuid5($run->id, $symbol), 'symbol' => $symbol, 'side' => $decision['action'], 'sleeve' => $rules['decision_sleeve'],
                            'type' => 'limit', 'quantity' => (string) $quantity, 'price_bound' => (string) $bound, 'expires_at' => $until->toIso8601String(),
                            'rationale' => $decision['rationale']], $assumptions, $marks, $execution->id);
                    $evidence['sizing'] = ['equity' => (string) $equity, 'target_quantity' => (string) $target, 'held_quantity' => (string) $held, 'order_quantity' => (string) $quantity, 'price_bound' => (string) $bound];
                    $results->push($this->record($run, $actor, $symbol, $order->status === 'rejected' ? 'rejected' : 'order_created',
                        $order->status === 'rejected' ? 'paper_order_risk_rejected' : null, $evidence, $execution->id, $order->id));
                } catch (DecisionFailure $failure) {
                    $results->push($this->record($run, $actor, $symbol, $failure->outcome, $failure->reason, $evidence, $execution->id));
                }
            }

            return $results;
        });
    }

    private function baseline(Portfolio $portfolio, array $session, $positions): ?array
    {
        $prior = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('accepted_at', '>=', CarbonImmutable::parse($session['opens_at']))
            ->where('status', '!=', 'rejected')->orderBy('id')->first();
        if ($prior) {
            return ['equity' => $prior->assumptions['session_open_equity'], 'kind' => 'existing_synthetic_order_assumption', 'order_id' => $prior->id];
        }
        if (app(PaperIncome::class)->receivable($portfolio)->compareTo(Decimal::from('0')) > 0) {
            return null; // A current receivable is not a verified session-opening valuation.
        }
        // A cash-only inception (or unchanged cash across the open) needs no invented security mark.
        if ($positions->contains(fn ($p) => Decimal::from($p->quantity)->compareTo(Decimal::from('0')) !== 0) ||
            CashEntry::where('paper_portfolio_id', $portfolio->id)->where('recorded_at', '>=', CarbonImmutable::parse($session['opens_at']))->where('kind', '!=', 'opening_balance')->exists()) {
            return null;
        }

        return ['equity' => $portfolio->cash, 'kind' => 'unchanged_cash_only_paper_ledger_or_inception', 'ledger_version' => $portfolio->ledger_version];
    }

    private function record(DecisionRun $run, int $actor, string $symbol, string $status, ?string $reason, array $evidence, ?int $executionId, ?int $orderId = null): OrderHandoff
    {
        return OrderHandoff::create(['decision_run_id' => $run->id, 'execution_version_id' => $executionId, 'sim_order_id' => $orderId,
            'actor_id' => $actor, 'symbol' => $symbol, 'status' => $status, 'reason' => $reason,
            'evidence' => $evidence + ['origin' => 'paper_only', 'broker_executable' => false, 'recorded_ledger_version' => $run->ledger_version], 'created_at' => now()]);
    }
}
