<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\PolicyApplication;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationObservation;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Simulated ledger only. Synthetic and broker observations use separate gates and immutable provenance. */
final class OfflineSimulator
{
    private const OPEN = ['open', 'partial'];

    public function __construct(private MarketCalendar $calendar) {}

    /** $marks contains one consistent observation mode for the candidate and every positive held position. */
    public function submit(McpConnection $connection, int $actor, int $policyId, int $ledgerVersion, array $intent, SimulationAssumptions $assumptions, array $marks, ?int $executionVersionId = null): SimulationOrder
    {
        $intent = $this->intent($intent);
        $real = ($marks[$intent['symbol']] ?? null) instanceof BrokerSimulationQuote;
        $identity = [$policyId, $ledgerVersion, $intent, $assumptions->data];
        if ($executionVersionId !== null) {
            $identity[] = $executionVersionId;
        }
        if ($real) {
            $identity[] = 'real_data_paper_simulation';
        }
        $hash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($connection, $actor, $policyId, $ledgerVersion, $intent, $assumptions, $marks, $hash, $executionVersionId) {
            [$current, $portfolio] = $this->lock($connection, $actor, true, ($marks[$intent['symbol']] ?? null) instanceof BrokerSimulationQuote);
            $repeat = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('request_id', $intent['request_id'])->first();
            if ($repeat) {
                abort_unless(hash_equals($repeat->intent_hash, $hash), 409, 'This scenario request already has a different intent.');

                return $repeat;
            }
            $policy = PolicyApplication::whereKey($policyId)->where('paper_portfolio_id', $portfolio->id)->where('revision', $portfolio->policy_revision)->firstOrFail();
            if ($executionVersionId !== null) {
                $execution = ExecutionPolicies::current($portfolio);
                abort_unless($execution?->id === $executionVersionId && $intent['sleeve'] === $execution->payload['decision_sleeve'], 409, 'Execution settings changed.');
                foreach (['fee_per_share', 'slippage_bps', 'quote_max_age_seconds', 'allow_fractional', 'allow_fractional_limit'] as $field) {
                    abort_unless($execution->payload[$field] === $assumptions->data[$field], 409, 'Fill assumptions differ from applied execution settings.');
                }
                abort_unless(($execution->payload['fee_bps'] ?? '0') === ($assumptions->data['fee_bps'] ?? '0'), 409, 'Percentage fee differs from applied execution settings.');
            }
            abort_unless($portfolio->ledger_version === $ledgerVersion, 409, 'Paper ledger changed.');
            $real = ($marks[$intent['symbol']] ?? null) instanceof BrokerSimulationQuote;
            abort_if($real && $executionVersionId === null, 409, 'Real-data paper orders require applied execution settings.');
            if ($real) {
                abort_unless(in_array($intent['symbol'], $execution->payload['allowed_symbols'], true) &&
                    $execution->payload[$intent['side'] === 'BUY' ? 'allow_entries' : 'allow_exits'] && $intent['type'] === $execution->payload['order_type'], 409, 'Owner execution permission denied.');
            }
            $this->validateMarks($current, $marks, $assumptions->data['quote_max_age_seconds'], $real);
            $order = SimulationOrder::create(['paper_portfolio_id' => $portfolio->id, 'policy_application_id' => $policy->id,
                'actor_id' => $actor, 'request_id' => $intent['request_id'], 'intent_hash' => $hash,
                'execution_version_id' => $executionVersionId,
                'intent' => $intent + ['origin' => $real ? 'real_data_paper_simulation' : 'offline_synthetic_scenario', 'policy_revision' => $policy->revision, 'ledger_version' => $ledgerVersion],
                'assumptions' => $assumptions->data, 'auth_generation' => $current->auth_generation,
                'symbol' => $intent['symbol'], 'sleeve' => $intent['sleeve'], 'side' => $intent['side'], 'type' => $intent['type'],
                'status' => 'open', 'quantity' => $intent['quantity'], 'filled_quantity' => '0', 'reserved_cash' => '0', 'price_bound' => $intent['price_bound'],
                'accepted_at' => now(), 'expires_at' => $intent['expires_at']]);
            $reason = $current->status !== 'connected' ? 'account_disconnected' : $this->admission($portfolio, $order, $policy, $marks);
            if ($reason) {
                $order->update(['status' => 'rejected']);
                $this->event($portfolio, $order, 'accepted', 'rejected', $reason, ['marks' => $this->snapshots($marks)]);

                return $order;
            }
            if ($order->side === 'BUY') {
                $reserve = $this->commitment($order, Decimal::from($order->quantity));
                $portfolio->reserved_cash = (string) Decimal::from($portfolio->reserved_cash)->plus($reserve);
                $order->reserved_cash = (string) $reserve;
                $order->save();
            } else {
                $position = $this->position($portfolio, $order);
                $position->reserved_quantity = (string) Decimal::from($position->reserved_quantity)->plus(Decimal::from($order->quantity));
                $position->save();
            }
            $portfolio->ledger_version++;
            $portfolio->save();
            $this->event($portfolio, $order, 'accepted', 'accepted', null, ['marks' => $this->snapshots($marks)]);

            return $order;
        });
    }

    /** One observation cannot supply its displayed side size more than once across competing orders. */
    public function observe(McpConnection $connection, int $actor, int $orderId, SimulationQuote|BrokerSimulationQuote $quote, array $marks = []): SimulationOrder
    {
        return DB::transaction(function () use ($connection, $actor, $orderId, $quote, $marks) {
            [$current, $portfolio] = $this->lock($connection, $actor, true, $quote instanceof BrokerSimulationQuote);
            $order = SimulationOrder::whereKey($orderId)->where('paper_portfolio_id', $portfolio->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, self::OPEN, true)) {
                return $order;
            }
            if ($reason = $this->invalidated($current, $portfolio, $order)) {
                return $this->close($portfolio, $order, $reason === 'order_expired' ? 'expired' : 'cancelled', $reason);
            }
            $real = $quote instanceof BrokerSimulationQuote;
            abort_unless(($order->intent['origin'] === 'real_data_paper_simulation') === $real, 409, 'Synthetic and real-data paper observations cannot mix.');
            $this->validateMarks($current, [$order->symbol => $quote] + $marks, $order->assumptions['quote_max_age_seconds'], $real);
            $d = $quote->data;
            abort_unless($d['symbol'] === $order->symbol, 422, 'Observation does not match order symbol.');
            $fingerprintData = $real ? array_intersect_key($d, array_flip(['symbol', 'bid', 'ask', 'bid_size', 'ask_size', 'liquidity_key', 'provenance'])) : $d;
            $fingerprint = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR));
            $observation = SimulationObservation::where('paper_portfolio_id', $portfolio->id)->where('source_key', $d['key'])->first();
            if ($observation) {
                abort_unless(hash_equals($observation->fingerprint, $fingerprint), 409, 'Observation key already identifies different evidence.');
            } else {
                $sampleKey = hash('sha256', $real ? $d['liquidity_key'] : $d['symbol'].':'.($d['source_at'] ?? $d['key']));
                abort_if(SimulationObservation::where('paper_portfolio_id', $portfolio->id)->where('sample_key', $sampleKey)->exists(), 409, 'This source sample already has another observation key.');
                $observation = SimulationObservation::create(['paper_portfolio_id' => $portfolio->id, 'source_key' => $d['key'],
                    'sample_key' => $sampleKey, 'fingerprint' => $fingerprint, 'snapshot' => $d, 'source_at' => $d['source_at'], 'retrieved_at' => $d['retrieved_at'], 'recorded_at' => now()]);
            }
            $eventKey = 'observation:'.$observation->id;
            if (SimulationEvent::where('sim_order_id', $order->id)->where('event_key', $eventKey)->exists()) {
                return $order;
            }
            $this->blockCorporateAction($portfolio, $quote, $order->sleeve);
            $reason = $quote->reason(CarbonImmutable::now(), $order->assumptions['quote_max_age_seconds']);
            if (! $reason && (CarbonImmutable::parse($d['source_at'])->lte($order->accepted_at) ||
                ($order->last_source_at && CarbonImmutable::parse($d['source_at'])->lte($order->last_source_at)))) {
                $reason = 'observation_not_after_order';
            }
            $session = $this->calendar->at(CarbonImmutable::now());
            $evaluation = ['session' => $session, 'marks' => $this->snapshots([$order->symbol => $quote] + $marks)];
            if (! $reason && ($session['status'] !== 'regular' || $this->calendar->at(CarbonImmutable::parse($d['source_at']))['status'] !== 'regular')) {
                $reason = 'outside_verified_session';
            }
            if (! $reason) {
                $reason = $this->admission($portfolio, $order, PolicyApplication::findOrFail($order->policy_application_id), [$order->symbol => $quote] + $marks, true);
            }
            $price = null;
            $size = $d[$order->side === 'BUY' ? 'ask_size' : 'bid_size'];
            if (! $reason && $size === null) {
                $reason = 'liquidity_unknown';
            }
            if (! $reason) {
                $price = $this->price($order, $quote);
                if ($price->compareTo(Decimal::from('0')) <= 0 || ($order->side === 'BUY' ? $price->compareTo(Decimal::from($order->price_bound)) > 0 :
                    $price->compareTo(Decimal::from($order->price_bound)) < 0)) {
                    $reason = $order->type === 'limit' ? 'limit_not_marketable' : 'market_price_bound';
                }
            }
            $quantity = Decimal::from('0');
            if (! $reason) {
                $used = Decimal::from('0');
                // Eloquent decimal casts preserve strings; SQL/SQLite SUM would introduce floats.
                $orderIds = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('side', $order->side)->pluck('id');
                foreach (SimulationEvent::where('observation_id', $observation->id)->whereIn('sim_order_id', $orderIds)->where('kind', 'fill')->get() as $fill) {
                    $used = $used->plus(Decimal::from($fill->quantity));
                }
                $remaining = Decimal::from($order->quantity)->minus(Decimal::from($order->filled_quantity));
                $fractional = $order->assumptions['allow_fractional'] && $d['fractional'] === true && ($order->type !== 'limit' || $order->assumptions['allow_fractional_limit']);
                $quantity = $this->min($remaining, Decimal::from($size)->minus($used))->rounded($fractional ? 8 : 0, RoundingMode::DOWN);
                if ($quantity->compareTo(Decimal::from('0')) <= 0) {
                    $reason = 'liquidity_exhausted';
                }
            }
            if ($reason) {
                // Even a usable but unmarketable sample advances the source watermark.
                if ($d['source_at'] !== null && CarbonImmutable::parse($d['source_at'])->gt($order->last_source_at ?? $order->accepted_at) &&
                    CarbonImmutable::parse($d['source_at'])->lte(CarbonImmutable::parse($d['retrieved_at'])) && CarbonImmutable::parse($d['retrieved_at'])->lte(now())) {
                    $order->update(['last_source_at' => $d['source_at']]);
                }
                $this->event($portfolio, $order, $eventKey, 'unfilled', $reason, $evaluation, $observation);

                return $order;
            }
            $this->fill($portfolio, $order, $quantity, $price, $observation, $eventKey, $evaluation);

            return $order;
        });
    }

    /** Close invalidated orders without requiring a usable quote or an enabled fill mode. */
    public function revalidate(McpConnection $connection, int $actor, int $orderId): SimulationOrder
    {
        return DB::transaction(function () use ($connection, $actor, $orderId) {
            [$current, $portfolio] = $this->lock($connection, $actor, false);
            $order = SimulationOrder::whereKey($orderId)->where('paper_portfolio_id', $portfolio->id)->lockForUpdate()->firstOrFail();
            if (in_array($order->status, self::OPEN, true) && ($reason = $this->invalidated($current, $portfolio, $order))) {
                return $this->close($portfolio, $order, $reason === 'order_expired' ? 'expired' : 'cancelled', $reason);
            }

            return $order;
        });
    }

    /** Conservative paper cancellation; do not reprice immutable intents across an issuer action. */
    public function cancelForCorporateAction(McpConnection $connection, int $actor, string $symbol): void
    {
        DB::transaction(function () use ($connection, $actor, $symbol) {
            [, $portfolio] = $this->lock($connection, $actor, false);
            foreach (SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('symbol', $symbol)->whereIn('status', self::OPEN)->orderBy('id')->get() as $order) {
                $expired = $order->expires_at->lte(now());
                $this->close($portfolio, $order, $expired ? 'expired' : 'cancelled', $expired ? 'order_expired' : 'corporate_action_detected');
            }
        });
    }

    /** Cancellation and expiry release resources even when the simulation switch has been turned off. */
    public function cancel(McpConnection $connection, int $actor, int $orderId): SimulationOrder
    {
        return DB::transaction(function () use ($connection, $actor, $orderId) {
            [, $portfolio] = $this->lock($connection, $actor, false);
            $order = SimulationOrder::whereKey($orderId)->where('paper_portfolio_id', $portfolio->id)->lockForUpdate()->firstOrFail();

            return in_array($order->status, self::OPEN, true) ? $this->close($portfolio, $order, 'cancelled', 'operator_cancelled') : $order;
        });
    }

    public function expire(McpConnection $connection, int $actor): int
    {
        return DB::transaction(function () use ($connection, $actor) {
            [, $portfolio] = $this->lock($connection, $actor, false);
            $orders = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->whereIn('status', self::OPEN)->where('expires_at', '<=', now())->orderBy('id')->get();
            foreach ($orders as $order) {
                $this->close($portfolio, $order, 'expired', 'order_expired');
            }

            return $orders->count();
        });
    }

    private function lock(McpConnection $connection, int $actor, bool $enabled = true, bool $real = false): array
    {
        abort_if($enabled && ! config($real ? 'paper.real_data_fills_enabled' : 'paper.offline_simulation_enabled'), 409, 'Selected paper simulation mode is disabled.');
        $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
        abort_unless($current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account binding changed.');
        $portfolio = Portfolio::where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->lockForUpdate()->firstOrFail();

        return [$current, $portfolio];
    }

    private function intent(array $intent): array
    {
        $rules = ['request_id' => ['required', 'uuid'], 'symbol' => ['required', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'],
            'side' => ['required', 'in:BUY,SELL'], 'sleeve' => ['required', 'in:active,long_term'], 'type' => ['required', 'in:limit,market'],
            'quantity' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/D'],
            'price_bound' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/D'],
            'expires_at' => ['required', 'date_format:Y-m-d\TH:i:sP'], 'rationale' => ['required', 'string', 'max:4000']];
        abort_if(array_diff(array_keys($intent), array_keys($rules)), 422, 'Unsupported synthetic intent field.');
        $valid = Validator::make($intent, $rules)->validate();
        $valid = array_replace(array_fill_keys(array_keys($rules), null), $valid);
        abort_unless(Decimal::from($valid['quantity'])->compareTo(Decimal::from('0')) > 0 && Decimal::from($valid['price_bound'])->compareTo(Decimal::from('0')) > 0, 422, 'Positive quantity and price bound required.');

        return $valid;
    }

    private function admission(Portfolio $portfolio, SimulationOrder $order, PolicyApplication $policy, array $marks, bool $existing = false): ?string
    {
        $now = CarbonImmutable::now();
        $session = $this->calendar->at($now);
        if ($session['status'] !== 'regular' || $order->assumptions['session_date'] !== $session['date']) {
            return 'outside_verified_session';
        }
        if ($order->expires_at->lte($now) || $order->expires_at->gt(CarbonImmutable::parse($session['closes_at']))) {
            return 'invalid_day_order_expiry';
        }
        $prior = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('id', '!=', $order->id)
            ->where('accepted_at', '>=', CarbonImmutable::parse($session['opens_at']))->where('accepted_at', '<', CarbonImmutable::parse($session['closes_at']))->where('status', '!=', 'rejected')->orderBy('id')->first();
        if ($prior && Decimal::from($prior->assumptions['session_open_equity'])->compareTo(Decimal::from($order->assumptions['session_open_equity'])) !== 0) {
            return 'session_baseline_changed';
        }
        $quote = $marks[$order->symbol] ?? null;
        if (! ($quote instanceof SimulationQuote || $quote instanceof BrokerSimulationQuote)) {
            return 'quote_unknown';
        }
        foreach ($marks as $symbol => $mark) {
            if (! ($mark instanceof SimulationQuote || $mark instanceof BrokerSimulationQuote) || $mark->data['symbol'] !== $symbol) {
                return 'invalid_marks';
            }
            $this->blockCorporateAction($portfolio, $mark, $order->sleeve);
            if ($reason = $mark->reason($now, $order->assumptions['quote_max_age_seconds'])) {
                return $reason;
            }
            if ($this->calendar->at(CarbonImmutable::parse($mark->data['source_at']))['status'] !== 'regular') {
                return 'outside_verified_session';
            }
        }
        if ($reason = $this->fractionalReason($order, $quote)) {
            return $reason;
        }
        $equity = Decimal::from($portfolio->cash)->plus(app(PaperIncome::class)->receivable($portfolio));
        $exposure = Decimal::from('0');
        $symbolExposure = Decimal::from('0');
        if (SimulationPosition::where('paper_portfolio_id', $portfolio->id)->whereNotNull('blocked_reason')->exists()) {
            return 'corporate_action_adjustment_required';
        }
        foreach (SimulationPosition::where('paper_portfolio_id', $portfolio->id)->get() as $position) {
            if (Decimal::from($position->quantity)->compareTo(Decimal::from('0')) === 0) {
                continue;
            }
            $mark = $marks[$position->symbol] ?? null;
            if (! ($mark instanceof SimulationQuote || $mark instanceof BrokerSimulationQuote)) {
                return 'position_mark_unknown';
            }
            $value = Decimal::from($position->quantity)->multipliedBy(Decimal::from($mark->data['bid']));
            $equity = $equity->plus($value);
            if ($position->sleeve === $order->sleeve) {
                $exposure = $exposure->plus($value);
            }
            if ($position->symbol === $order->symbol) {
                $symbolExposure = $symbolExposure->plus($value);
            }
        }
        if ($order->side === 'SELL') {
            $position = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('symbol', $order->symbol)->where('sleeve', $order->sleeve)->first();
            $available = Decimal::from($position?->quantity ?? '0')->minus(Decimal::from($position?->reserved_quantity ?? '0'));
            $remaining = Decimal::from($order->quantity)->minus(Decimal::from($order->filled_quantity));
            if ($existing) {
                $available = $available->plus($remaining);
            }

            return $available->compareTo($remaining) < 0 ? 'insufficient_unreserved_shares' : null;
        }
        $baseline = Decimal::from($order->assumptions['session_open_equity']);
        $loss = $baseline->minus($equity)->multipliedBy(Decimal::from('100'));
        if ($loss->compareTo($baseline->multipliedBy(Decimal::from($policy->snapshot['rules']['daily_loss_pause_pct']))) >= 0) {
            return 'scenario_daily_loss_pause';
        }
        foreach (SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('id', '!=', $order->id)->where('side', 'BUY')->whereIn('status', self::OPEN)->get() as $pending) {
            $notional = Decimal::from($pending->quantity)->minus(Decimal::from($pending->filled_quantity))->multipliedBy(Decimal::from($pending->price_bound));
            if ($pending->sleeve === $order->sleeve) {
                $exposure = $exposure->plus($notional);
            }
            if ($pending->symbol === $order->symbol) {
                $symbolExposure = $symbolExposure->plus($notional);
            }
        }
        $remaining = Decimal::from($order->quantity)->minus(Decimal::from($order->filled_quantity));
        $notional = $remaining->multipliedBy(Decimal::from($order->price_bound));
        $commitment = $this->commitment($order, $remaining);
        $available = Decimal::from($portfolio->cash)->minus(Decimal::from($portfolio->reserved_cash));
        if ($existing) {
            $available = $available->plus(Decimal::from($order->reserved_cash));
        }
        if ($available->compareTo($commitment) < 0) {
            return 'insufficient_unreserved_cash';
        }
        $rules = $policy->snapshot['rules'];
        if ($symbolExposure->plus($notional)->multipliedBy(Decimal::from('100'))->compareTo($equity->multipliedBy(Decimal::from($rules['max_position_pct']))) > 0) {
            return 'position_limit_exceeded';
        }
        if ($exposure->plus($notional)->multipliedBy(Decimal::from('100'))->compareTo($equity->multipliedBy(Decimal::from($rules[$order->sleeve.'_pct']))) > 0) {
            return 'sleeve_limit_exceeded';
        }
        if ($available->minus($commitment)->multipliedBy(Decimal::from('100'))->compareTo($equity->multipliedBy(Decimal::from($rules['cash_pct']))) < 0) {
            return 'cash_floor_exceeded';
        }

        return null;
    }

    private function fractionalReason(SimulationOrder $order, SimulationQuote|BrokerSimulationQuote $quote): ?string
    {
        $quantity = Decimal::from($order->quantity);
        $fractional = $quantity->compareTo($quantity->rounded(0, RoundingMode::DOWN)) !== 0;
        if ($fractional && (! $order->assumptions['allow_fractional'] || $quote->data['fractional'] !== true ||
            ($order->type === 'limit' && ! $order->assumptions['allow_fractional_limit']))) {
            return 'fractional_order_unsupported';
        }

        return null;
    }

    private function invalidated(McpConnection $connection, Portfolio $portfolio, SimulationOrder $order): ?string
    {
        if ($order->expires_at->lte(now())) {
            return 'order_expired';
        }
        if ($connection->status !== 'connected' || $connection->auth_generation !== $order->auth_generation) {
            return 'authorization_changed';
        }
        $policy = PolicyApplication::findOrFail($order->policy_application_id);
        if ($order->execution_version_id !== null && ExecutionPolicies::current($portfolio)?->id !== $order->execution_version_id) {
            return 'execution_settings_changed';
        }

        return $policy->revision !== $portfolio->policy_revision ? 'policy_changed' : null;
    }

    private function price(SimulationOrder $order, SimulationQuote|BrokerSimulationQuote $quote): Decimal
    {
        $buy = $order->side === 'BUY';
        $reference = Decimal::from($quote->data[$buy ? 'ask' : 'bid']);
        $multiplier = Decimal::from('1')->plus(Decimal::from($order->assumptions['slippage_bps'])->dividedBy(Decimal::from('10000'), 12)->multipliedBy(Decimal::from($buy ? '1' : '-1')));

        return $reference->multipliedBy($multiplier)->rounded(8, $buy ? RoundingMode::CEILING : RoundingMode::FLOOR);
    }

    private function commitment(SimulationOrder $order, Decimal $quantity): Decimal
    {
        $price = Decimal::from($order->price_bound);

        return $quantity->multipliedBy($price)->plus($this->fee($order, $quantity, $price, RoundingMode::CEILING));
    }

    private function fee(SimulationOrder $order, Decimal $quantity, Decimal $price, RoundingMode $rounding = RoundingMode::DOWN): Decimal
    {
        $rate = Decimal::from($order->assumptions['fee_bps'] ?? '0')->dividedBy(Decimal::from('10000'), 12);

        // Round fees down to ledger precision; reserve upward so partial fills cannot overdraw cash.
        return $quantity->multipliedBy(Decimal::from($order->assumptions['fee_per_share']))
            ->plus($quantity->multipliedBy($price)->multipliedBy($rate))->rounded(18, $rounding);
    }

    private function fill(Portfolio $portfolio, SimulationOrder $order, Decimal $quantity, Decimal $price, SimulationObservation $observation, string $eventKey, array $evaluation): void
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

    private function close(Portfolio $portfolio, SimulationOrder $order, string $status, string $reason): SimulationOrder
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

    private function position(Portfolio $portfolio, SimulationOrder $order): SimulationPosition
    {
        return SimulationPosition::firstOrCreate(['paper_portfolio_id' => $portfolio->id, 'symbol' => $order->symbol, 'sleeve' => $order->sleeve],
            ['quantity' => '0', 'reserved_quantity' => '0', 'cost_basis' => '0', 'realized_pnl' => '0']);
    }

    private function blockCorporateAction(Portfolio $portfolio, SimulationQuote|BrokerSimulationQuote $quote, string $sleeve): void
    {
        $d = $quote->data;
        if ($d['corporate_actions_clear'] === false && $d['source_at'] !== null &&
            CarbonImmutable::parse($d['source_at'])->lte(CarbonImmutable::parse($d['retrieved_at'])) && CarbonImmutable::parse($d['retrieved_at'])->lte(now())) {
            SimulationPosition::firstOrCreate(['paper_portfolio_id' => $portfolio->id, 'symbol' => $quote->data['symbol'], 'sleeve' => $sleeve],
                ['quantity' => '0', 'reserved_quantity' => '0', 'cost_basis' => '0', 'realized_pnl' => '0']);
            SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('symbol', $quote->data['symbol'])
                ->update(['blocked_reason' => 'corporate_action_adjustment_required']);
        }
    }

    private function event(Portfolio $portfolio, SimulationOrder $order, string $key, string $kind, ?string $reason, array $evidence, ?SimulationObservation $observation = null, ?Decimal $quantity = null, ?Decimal $price = null, ?Decimal $fee = null, ?Decimal $delta = null): SimulationEvent
    {
        return SimulationEvent::create(['sim_order_id' => $order->id, 'observation_id' => $observation?->id, 'event_key' => $key,
            'kind' => $kind, 'reason' => $reason, 'quantity' => (string) ($quantity ?? Decimal::from('0')), 'price' => $price === null ? null : (string) $price,
            'fee' => (string) ($fee ?? Decimal::from('0')), 'cash_delta' => (string) ($delta ?? Decimal::from('0')),
            'ledger_version' => $portfolio->ledger_version, 'evidence' => $evidence + ['mode' => ($order->intent['origin'] ?? null) === 'real_data_paper_simulation' ? 'real_data_paper_simulation' : 'offline_synthetic_simulation', 'order_status' => $order->status], 'recorded_at' => now()]);
    }

    private function snapshots(array $marks): array
    {
        return array_map(fn ($quote) => ($quote instanceof SimulationQuote || $quote instanceof BrokerSimulationQuote) ? $quote->data : null, $marks);
    }

    private function validateMarks(McpConnection $connection, array $marks, int $maxAge, bool $real): void
    {
        foreach ($marks as $quote) {
            abort_unless(($quote instanceof BrokerSimulationQuote) === $real, 409, 'Synthetic and real-data paper marks cannot mix.');
            if ($quote instanceof BrokerSimulationQuote) {
                $quote->assertCurrent($connection, $maxAge);
            }
        }
    }

    private function min(Decimal $a, Decimal $b): Decimal
    {
        return $a->compareTo($b) < 0 ? $a : $b;
    }
}
