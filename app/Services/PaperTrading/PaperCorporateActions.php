<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\CorporateActionRecord;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\User;
use App\Services\Broker\Agentic\Capabilities;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Paper ledger only. Consumes immutable adapter inputs, never model/user event JSON or brokerage mutations. */
final class PaperCorporateActions
{
    public const UNEXPOSED = 'settled_before_paper_exposure';

    /** Reviewed terms this ledger can apply to held shares. Anything else needs review or provable non-exposure. */
    public static function supported(array $event): bool
    {
        if (! in_array($event['type'], ['forward_splits', 'reverse_splits', 'cash_dividends'], true) || ! empty($event['missing_fields']) || ! isset($event['dates']['ex_date'])) {
            return false;
        }
        if ($event['type'] === 'cash_dividends') {
            $d = $event['dates'];

            // A special dividend on the regular ex <= record <= payable calendar has ordinary entitlement. Distributions of at
            // least 25% move the ex-date after payment and use due bills, so either signal still requires review.
            return ($event['flags']['foreign'] ?? null) === false && is_bool($event['flags']['special'] ?? null) &&
                isset($d['record_date'], $d['payable_date']) && $d['record_date'] >= $d['ex_date'] && $d['payable_date'] >= $d['record_date'] &&
                ($d['due_bill_on_date'] ?? null) === null && ($d['due_bill_off_date'] ?? null) === null && ($d['due_bill_redemption_date'] ?? null) === null;
        }
        try {
            $new = Decimal::from($event['rates']['new_rate'] ?? null);
            $old = Decimal::from($event['rates']['old_rate'] ?? null);

            return $new->compareTo(Decimal::from('0')) > 0 && $old->compareTo(Decimal::from('0')) > 0 &&
                ($event['type'] === 'forward_splits' ? $new->compareTo($old) > 0 : $new->compareTo($old) < 0);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** Start of the last reported New York date; before it, an unexposed portfolio could still acquire exposure. */
    public static function settledAt(array $event): ?CarbonImmutable
    {
        $dates = array_filter($event['dates'] ?? [], 'is_string');

        return $dates === [] ? null : CarbonImmutable::parse(max($dates), 'America/New_York')->startOfDay()->utc();
    }

    public function process(McpConnection $connection, int $actor, int $inputId): Collection
    {
        abort_unless(config('paper.corporate_actions_enabled'), 409, 'Paper corporate-action processing is disabled.');

        return DB::transaction(function () use ($connection, $actor, $inputId) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->status === 'connected' && $current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account authorization changed.');
            $portfolio = Portfolio::where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->lockForUpdate()->firstOrFail();
            $input = MarketInput::whereKey($inputId)->where('mcp_connection_id', $current->id)->where('scope', $portfolio->scope)->firstOrFail();
            $now = CarbonImmutable::now();
            abort_unless(($input->payload['quote']['symbol'] ?? null) === $input->symbol && $input->origin === 'broker_read' && $input->recorded_at->lte($now) && $input->retrieved_at->lte($now) &&
                app(PaperMarketInputs::class)->latest($current, $input->symbol, $now)?->id === $input->id, 409, 'Select the newest available broker input.');
            $coverage = app(CorporateActionCoverage::class)->inspect($input->payload, $now, 300);
            $read = $input->payload['corporate_action_reads']['alpaca'] ?? [];
            abort_unless(in_array($coverage['status'], ['process_window_complete_events_require_review', 'process_window_complete_no_events_returned'], true) &&
                CarbonImmutable::parse($read['retrieved_at'])->lte($input->retrieved_at), 409, 'Complete fresh action query required.');
            $execution = ExecutionPolicies::current($portfolio);
            abort_unless($execution && $execution->payload['initial_holdings'] === 'cash_only', 409, 'Known cash-only paper inception required.');
            $events = $read['events'];
            $results = collect();
            $ids = array_column($events, 'id');
            abort_unless(count($ids) === count($events) && count($ids) === count(array_unique($ids)), 409, 'Unique action IDs required.');

            $firstFill = self::firstFill($portfolio, $input->symbol, $now);
            // A vanished event is not a cancellation instruction, even in a complete process-date refresh.
            $prior = CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('symbol', $input->symbol)->where('phase', 'observation')->get();
            foreach ($prior as $record) {
                $process = $record->payload['event']['dates']['process_date'] ?? null;
                if ($process !== null && $process >= $read['start'] && $process <= $read['end'] && ! in_array($record->provider_event_id, $ids, true) &&
                    ! self::beforeExposure($record->payload['event'], $firstFill)) {
                    return collect([$this->freeze($current, $portfolio, $actor, $input, $record->payload['event'], $record->fingerprint, 'event_missing_from_refresh')]);
                }
            }
            $dated = [];
            foreach ($events as $event) {
                $fingerprint = $this->fingerprint($event);
                $versions = CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('provider_event_id', $event['id'])->where('fingerprint', '!=', $fingerprint)->get();
                if ($versions->isNotEmpty() && ! ($versions->every(fn ($r) => self::beforeExposure($r->payload['event'], $firstFill)) && self::beforeExposure($event, $firstFill))) {
                    return collect([$this->freeze($current, $portfolio, $actor, $input, $event, $fingerprint, 'provider_event_corrected_requires_review')]);
                }
                $date = $event['dates']['ex_date'] ?? $event['dates']['effective_date'] ?? null;
                if ($date !== null) {
                    $dated[$date] = ($dated[$date] ?? 0) + 1;
                }
            }
            usort($events, fn ($a, $b) => strcmp($a['dates']['ex_date'] ?? $a['dates']['effective_date'] ?? '', $b['dates']['ex_date'] ?? $b['dates']['effective_date'] ?? ''));
            $released = false;
            foreach ($events as $event) {
                $fingerprint = $this->fingerprint($event);
                $date = $event['dates']['ex_date'] ?? $event['dates']['effective_date'] ?? null;
                $effective = $date ? CarbonImmutable::parse($date, 'America/New_York')->startOfDay()->utc() : null;
                $observation = $this->record($portfolio, $actor, $input, $event, $fingerprint, 'observation', 'observed', null, $effective);
                if ($this->application($portfolio, $event['id'], $fingerprint)) {
                    $results->push($this->application($portfolio, $event['id'], $fingerprint));

                    continue;
                }
                // Every reported date passed before this portfolio first filled the symbol: no entitlement, basis or quantity to change.
                if (self::unexposed($event, $firstFill, $now)) {
                    if (! $released) {
                        app(OfflineSimulator::class)->cancelForCorporateAction($current, $actor, $input->symbol);
                        $portfolio->refresh();
                        $released = true;
                    }
                    $results->push($this->record($portfolio, $actor, $input, $event, $fingerprint, 'application', 'no_effect', self::UNEXPOSED, self::settledAt($event),
                        ['cash_delta' => '0', 'basis' => $firstFill ? 'settled_before_first_paper_fill' : 'no_paper_fills', 'first_fill_at' => $firstFill?->toISOString()]));

                    continue;
                }
                if ($firstFill === null || $effective?->gt($now)) {
                    $results->push($observation); // Until exposure exists or the event settles, there is nothing to apply.

                    continue;
                }
                if (! $effective || ! empty($event['missing_fields']) || ($dated[$date] ?? 0) > 1 ||
                    ! in_array($event['type'], ['forward_splits', 'reverse_splits', 'cash_dividends'], true)) {
                    $results->push($this->freeze($current, $portfolio, $actor, $input, $event, $fingerprint, 'unsupported_or_ambiguous_action'));

                    break;
                }
                if (SimulationPosition::where('paper_portfolio_id', $portfolio->id)->whereNotNull('blocked_reason')->exists()) {
                    $results->push($this->freeze($current, $portfolio, $actor, $input, $event, $fingerprint, 'existing_action_review_block'));

                    break;
                }
                if (! $this->historyMatches($portfolio, $input->symbol)) {
                    $results->push($this->freeze($current, $portfolio, $actor, $input, $event, $fingerprint, 'paper_position_history_unverified'));

                    break;
                }
                $captured = CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('provider_event_id', $event['id'])->where('fingerprint', $fingerprint)->where('phase', 'entitlement')->exists();
                if (! $captured) {
                    app(OfflineSimulator::class)->cancelForCorporateAction($current, $actor, $input->symbol);
                }
                $portfolio->refresh();
                $outcome = $event['type'] === 'cash_dividends'
                    ? $this->dividend($current, $portfolio, $actor, $input, $event, $fingerprint, $effective, $now)
                    : $this->split($current, $portfolio, $actor, $input, $event, $fingerprint, $effective);
                $results->push($outcome);
                if ($outcome->status === 'blocked') {
                    break;
                }
            }

            return $results;
        });
    }

    private function split(McpConnection $connection, Portfolio $portfolio, int $actor, MarketInput $input, array $event, string $fingerprint, CarbonImmutable $effective): CorporateActionRecord
    {
        $before = $this->quantitiesAt($portfolio, $input->symbol, $effective);
        if (! $this->positive($before)) {
            return $this->record($portfolio, $actor, $input, $event, $fingerprint, 'application', 'no_effect', 'no_pre_event_holdings', $effective);
        }
        if ($this->fills($portfolio, $input->symbol)->where('recorded_at', '>=', $effective)->exists()) {
            return $this->freeze($connection, $portfolio, $actor, $input, $event, $fingerprint, 'late_split_intervening_trades');
        }
        if (CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('symbol', $input->symbol)->where('effective_at', '>=', $effective)
            ->where(fn ($q) => $q->where('phase', 'entitlement')->orWhere(fn ($a) => $a->where('phase', 'application')->where('status', 'applied')))->exists()) {
            return $this->freeze($connection, $portfolio, $actor, $input, $event, $fingerprint, 'late_action_dependency_requires_review');
        }
        try {
            $new = Decimal::from($event['rates']['new_rate']);
            $old = Decimal::from($event['rates']['old_rate']);
            if ($new->compareTo(Decimal::from('0')) <= 0 || $old->compareTo(Decimal::from('0')) <= 0 ||
                ($event['type'] === 'forward_splits' ? $new->compareTo($old) <= 0 : $new->compareTo($old) >= 0)) {
                throw new \InvalidArgumentException;
            }
            $changes = [];
            foreach ($before as $sleeve => $quantity) {
                $after = Decimal::from($quantity)->multipliedBy($new)->dividedBy($old, 0, RoundingMode::UNNECESSARY);
                $position = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('symbol', $input->symbol)->where('sleeve', $sleeve)->firstOrFail();
                $changes[$sleeve] = ['quantity_before' => $quantity, 'quantity_after' => (string) $after,
                    'quantity_delta' => (string) $after->minus(Decimal::from($quantity)), 'cost_basis_unchanged' => $position->cost_basis];
            }
        } catch (\InvalidArgumentException|MathException) {
            return $this->freeze($connection, $portfolio, $actor, $input, $event, $fingerprint, 'split_fraction_or_ratio_requires_review');
        }
        foreach ($changes as $sleeve => $change) {
            SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('symbol', $input->symbol)->where('sleeve', $sleeve)
                ->update(['quantity' => $change['quantity_after']]);
        }
        $portfolio->ledger_version++;
        $portfolio->save();

        return $this->record($portfolio, $actor, $input, $event, $fingerprint, 'application', 'applied', null, $effective, ['changes' => $changes, 'cash_delta' => '0']);
    }

    private function dividend(McpConnection $connection, Portfolio $portfolio, int $actor, MarketInput $input, array $event, string $fingerprint, CarbonImmutable $effective, CarbonImmutable $now): CorporateActionRecord
    {
        $d = $event['dates'];
        if (! self::supported($event)) {
            return $this->freeze($connection, $portfolio, $actor, $input, $event, $fingerprint, 'dividend_terms_require_review');
        }
        $rate = Decimal::from($event['rates']['rate']);
        $quantities = $this->quantitiesAt($portfolio, $input->symbol, $effective);
        $gross = Decimal::from('0');
        $entitlements = [];
        foreach ($quantities as $sleeve => $quantity) {
            $amount = Decimal::from($quantity)->multipliedBy($rate);
            try {
                $amount = $amount->rounded(18, RoundingMode::UNNECESSARY);
            } catch (MathException) {
                return $this->freeze($connection, $portfolio, $actor, $input, $event, $fingerprint, 'dividend_precision_requires_review');
            }
            $entitlements[$sleeve] = ['quantity' => $quantity, 'gross_amount' => (string) $amount];
            $gross = $gross->plus($amount);
        }
        if ($gross->compareTo(Decimal::from('0')) > 0 && ! CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('provider_event_id', $event['id'])->where('fingerprint', $fingerprint)->where('phase', 'entitlement')->exists()) {
            $portfolio->ledger_version++; // A booked receivable changes paper equity even before cash payment.
            $portfolio->save();
        }
        $entitlement = $this->record($portfolio, $actor, $input, $event, $fingerprint, 'entitlement', 'captured', null, $effective,
            ['entitlements' => $entitlements, 'gross_amount' => (string) $gross, 'basis' => 'holdings_before_ex_date', 'taxes' => 'not_simulated']);
        $payable = CarbonImmutable::parse($d['payable_date'], 'America/New_York')->startOfDay()->utc();
        if ($payable->gt($now)) {
            return $entitlement;
        }
        // Use the immutable entitlement, including when the shares were sold before payment.
        $gross = Decimal::from($entitlement->payload['gross_amount']);
        if ($gross->compareTo(Decimal::from('0')) > 0) {
            $portfolio->cash = (string) Decimal::from($portfolio->cash)->plus($gross);
            $portfolio->ledger_version++;
            $portfolio->save();
        }
        $application = $this->record($portfolio, $actor, $input, $event, $fingerprint, 'application', $gross->compareTo(Decimal::from('0')) > 0 ? 'applied' : 'no_effect',
            null, $payable, ['cash_delta' => (string) $gross, 'entitlement_id' => $entitlement->id, 'income_kind' => 'gross_simulated_dividend', 'special' => $event['flags']['special']]);
        if ($gross->compareTo(Decimal::from('0')) > 0) {
            CashEntry::create(['paper_portfolio_id' => $portfolio->id, 'actor_id' => $actor, 'reference' => 'paper-action:'.$application->id,
                'kind' => 'simulated_dividend', 'amount' => (string) $gross, 'balance_after' => $portfolio->cash, 'ledger_version' => $portfolio->ledger_version, 'recorded_at' => now()]);
        }

        return $application;
    }

    private function historyMatches(Portfolio $portfolio, string $symbol): bool
    {
        if ($this->fills($portfolio, $symbol)->get()->contains(fn ($fill) => SimulationOrder::findOrFail($fill->sim_order_id)->intent['origin'] !== 'real_data_paper_simulation')) {
            return false;
        }
        $expected = $this->quantitiesAt($portfolio, $symbol, CarbonImmutable::now()->addMicrosecond());
        foreach (SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('symbol', $symbol)->get() as $position) {
            if (Decimal::from($position->quantity)->compareTo(Decimal::from($expected[$position->sleeve] ?? '0')) !== 0) {
                return false;
            }
            unset($expected[$position->sleeve]);
        }

        return ! $this->positive($expected);
    }

    private function quantitiesAt(Portfolio $portfolio, string $symbol, CarbonImmutable $cutoff): array
    {
        $quantities = [];
        foreach ($this->fills($portfolio, $symbol)->where('recorded_at', '<', $cutoff->format('Y-m-d H:i:s.u'))->orderBy('id')->get() as $fill) {
            $order = SimulationOrder::findOrFail($fill->sim_order_id);
            $delta = Decimal::from($fill->quantity)->multipliedBy(Decimal::from($order->side === 'BUY' ? '1' : '-1'));
            $quantities[$order->sleeve] = (string) Decimal::from($quantities[$order->sleeve] ?? '0')->plus($delta);
        }
        foreach (CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('symbol', $symbol)->where('phase', 'application')->where('status', 'applied')
            ->whereIn('event_type', ['forward_splits', 'reverse_splits'])->where('effective_at', '<', $cutoff->format('Y-m-d H:i:s.u'))->get() as $adjustment) {
            foreach ($adjustment->payload['changes'] as $sleeve => $change) {
                $quantities[$sleeve] = (string) Decimal::from($quantities[$sleeve] ?? '0')->plus(Decimal::from($change['quantity_delta']));
            }
        }

        return $quantities;
    }

    private function fills(Portfolio $portfolio, string $symbol)
    {
        return self::fillQuery($portfolio, $symbol);
    }

    private static function fillQuery(Portfolio $portfolio, string $symbol)
    {
        return SimulationEvent::where('kind', 'fill')->whereIn('sim_order_id', SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('symbol', $symbol)->select('id'));
    }

    /** First paper fill recorded by the cutoff; null means the portfolio has never held the symbol. */
    public static function firstFill(Portfolio $portfolio, string $symbol, CarbonImmutable $cutoff): ?CarbonImmutable
    {
        return self::fillQuery($portfolio, $symbol)->where('recorded_at', '<=', $cutoff->utc()->format('Y-m-d H:i:s.u'))->orderBy('recorded_at')->orderBy('id')->first()?->recorded_at;
    }

    /** Complete, settled by the cutoff and entirely before the first fill: reconcilable without a typed adjustment. */
    public static function unexposed(array $event, ?CarbonImmutable $firstFill, CarbonImmutable $cutoff): bool
    {
        $settled = self::settledAt($event);

        return empty($event['missing_fields']) && $settled !== null && $settled->lte($cutoff) && self::beforeExposure($event, $firstFill);
    }

    /** No reported date of the event falls after the first fill, so it cannot have touched paper holdings. */
    public static function beforeExposure(array $event, ?CarbonImmutable $firstFill): bool
    {
        $settled = self::settledAt($event);

        return $firstFill === null || ($settled !== null && $settled->lte($firstFill));
    }

    private function positive(array $quantities): bool
    {
        return collect($quantities)->contains(fn ($q) => Decimal::from($q)->compareTo(Decimal::from('0')) > 0);
    }

    public static function fingerprint(array $event): string
    {
        $semantic = array_intersect_key($event, array_flip(['id', 'type', 'symbols', 'dates', 'rates', 'flags']));
        foreach ($semantic['rates'] ?? [] as $key => $rate) {
            $semantic['rates'][$key] = $rate === null ? null : (string) BigDecimal::of($rate)->stripTrailingZeros();
        }

        return Capabilities::fingerprint($semantic);
    }

    private function application(Portfolio $portfolio, string $eventId, string $fingerprint): ?CorporateActionRecord
    {
        return CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('provider_event_id', $eventId)->where('fingerprint', $fingerprint)->where('phase', 'application')->first();
    }

    private function freeze(McpConnection $connection, Portfolio $portfolio, int $actor, MarketInput $input, array $event, string $fingerprint, string $reason): CorporateActionRecord
    {
        app(OfflineSimulator::class)->cancelForCorporateAction($connection, $actor, $input->symbol);
        $portfolio->refresh();
        $changed = ! SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('symbol', $input->symbol)->exists() ||
            SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('symbol', $input->symbol)
                ->where(fn ($q) => $q->whereNull('blocked_reason')->orWhere('blocked_reason', '!=', 'corporate_action_review_required'))->exists();
        $sleeve = ExecutionPolicies::current($portfolio)->payload['decision_sleeve'];
        SimulationPosition::firstOrCreate(['paper_portfolio_id' => $portfolio->id, 'symbol' => $input->symbol, 'sleeve' => $sleeve]);
        SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('symbol', $input->symbol)->update(['blocked_reason' => 'corporate_action_review_required']);

        if ($changed) {
            $portfolio->ledger_version++;
            $portfolio->save();
        }

        return $this->record($portfolio, $actor, $input, $event, $fingerprint, 'block', 'blocked', $reason, null);
    }

    private function record(Portfolio $portfolio, int $actor, MarketInput $input, array $event, string $fingerprint, string $phase, string $status, ?string $reason, ?CarbonImmutable $effective, array $extra = []): CorporateActionRecord
    {
        return CorporateActionRecord::firstOrCreate(['paper_portfolio_id' => $portfolio->id, 'provider_event_id' => $event['id'], 'fingerprint' => $fingerprint, 'phase' => $phase],
            ['market_input_id' => $input->id, 'actor_id' => $actor, 'symbol' => $input->symbol, 'event_type' => $event['type'],
                'status' => $status, 'reason' => $reason, 'effective_at' => $effective, 'ledger_version' => $portfolio->ledger_version,
                'payload' => $extra + ['event' => $event, 'mode' => 'paper_corporate_action', 'broker_executable' => false], 'recorded_at' => now()]);
    }
}
