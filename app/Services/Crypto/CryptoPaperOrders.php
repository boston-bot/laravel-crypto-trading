<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Models\PaperTrading\InstrumentPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SignalOrderHandoff;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategySignal;
use App\Services\PaperTrading\InstrumentSizing;
use App\Services\PaperTrading\PaperFillPrice;
use App\Services\PaperTrading\PaperLedger;
use App\Services\PaperTrading\PortfolioEntryLimits;
use App\Services\PaperTrading\RiskSizer;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

/**
 * C3 simulated limit orders for the crypto sleeve. Signals cross into the shared paper ledger only here, against stored
 * Coinbase best bid/ask and displayed size. Callers hold the portfolio row lock inside one transaction. Never a venue order.
 */
final class CryptoPaperOrders
{
    private const OPEN = ['open', 'partial'];

    public function __construct(private PaperLedger $ledger, private CryptoPaperValuation $valuation) {}

    /** Expire, cancel or fill existing orders using only observations newer than each order's watermark. */
    public function advance(Portfolio $portfolio, InstrumentPolicy $policy, CarbonImmutable $cutoff): array
    {
        $summary = ['expired' => 0, 'cancelled' => 0, 'fills' => 0, 'unfilled' => 0];
        $maxAge = $policy->payload['parameters']['values']['quote_max_age_seconds'];
        foreach (SimulationOrder::where('paper_portfolio_id', $portfolio->id)->whereIn('status', self::OPEN)->orderBy('id')->lockForUpdate()->get() as $order) {
            if ($order->expires_at->lte($cutoff)) {
                $this->ledger->close($portfolio, $order, 'expired', 'order_expired');
                $summary['expired']++;
            } elseif ($order->instrument_policy_id !== $policy->id) {
                $this->ledger->close($portfolio, $order, 'cancelled', 'policy_changed');
                $summary['cancelled']++;
            } elseif ($quote = $this->valuation->quote($order->symbol, $cutoff, $maxAge, $this->watermark($order))) {
                $summary[$this->attempt($portfolio, $order, $quote, $cutoff, false) ? 'fills' : 'unfilled']++;
            }
        }

        return $summary;
    }

    /** One handoff per signal. Size and capacity are recomputed from the current ledger with the signal's own quote. */
    public function submit(Portfolio $portfolio, InstrumentPolicy $policy, StrategySignal $signal, int $cycleId, CarbonImmutable $cutoff): SignalOrderHandoff
    {
        if ($prior = SignalOrderHandoff::where('signal_id', $signal->id)->first()) {
            return $prior;
        }
        $evidence = $signal->payload['evidence'];
        $record = fn (string $status, ?string $reason, ?SimulationOrder $order = null, array $facts = []) => SignalOrderHandoff::create([
            'paper_portfolio_id' => $portfolio->id, 'signal_id' => $signal->id, 'signal_policy_id' => null, 'sim_order_id' => $order?->id,
            'actor_id' => $portfolio->actor_id, 'status' => $status, 'reason' => $reason, 'recorded_at' => now(),
            'payload' => $facts + ['signal_input_fingerprint' => $signal->input_fingerprint, 'ledger_version_before' => $portfolio->ledger_version,
                'mode' => 'crypto_signal_paper_simulation', 'broker_executable' => false]]);
        if ($signal->status !== 'signal') {
            return $record('no_order', $signal->reason);
        }
        abort_unless($signal->paper_portfolio_id === $portfolio->id && $signal->cycle_id === $cycleId && $evidence['policy_id'] === $policy->id, 409, 'Signal is not from the current crypto cycle.');
        $p = $policy->payload['parameters']['values'];
        $product = $evidence['product'];
        $quote = $evidence['quote'];
        $cost = $evidence['cost_schedule'];
        if ($cost === null || ! CarbonImmutable::parse($cost['valid_until'])->gt($cutoff)) {
            return $record('unavailable', 'product_fee_schedule_unavailable_or_expired');
        }
        if (SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('symbol', $signal->symbol)->whereIn('status', self::OPEN)->exists()) {
            return $record('no_order', 'symbol_has_open_order');
        }
        $position = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('symbol', $signal->symbol)->where('sleeve', 'active')->first();
        $assumptions = ['mode' => 'crypto_displayed_book_v1', 'slippage_bps' => $p['slippage_bps'], 'quote_max_age_seconds' => $p['quote_max_age_seconds'],
            'cost_schedule' => $cost, 'base_increment' => $product['base_increment'], 'base_min_size' => $product['base_min_size'],
            'quote_min_size' => $product['quote_min_size'], 'price_increment' => $product['price_increment']];
        if ($signal->side === 'BUY') {
            $held = $this->valuation->held($portfolio);
            $pending = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('side', 'BUY')->whereIn('status', self::OPEN)->whereNotIn('symbol', array_keys($held))->count();
            $value = $this->valuation->value($portfolio, $cutoff, $p['quote_max_age_seconds']);
            $baseline = $this->valuation->baseline($portfolio, $cutoff);
            $pause = PortfolioEntryLimits::reason($value['equity'], $baseline['equity'] ?? null, count($held) + $pending, $policy->payload['owner'], $this->valuation->latched($portfolio, $cutoff));
            if ($pause !== null) {
                return $record('no_order', $pause, facts: ['valuation' => $value, 'baseline' => $baseline]);
            }
            $bound = InstrumentSizing::up(PaperFillPrice::calculate('BUY', $quote['ask'], $p['slippage_bps'], 18), $product['price_increment']);
            $stop = $bound->minus(Decimal::from($signal->payload['result']['facts']['entry_atr'])->multipliedBy(Decimal::from($p['initial_stop_atr'])));
            $available = Decimal::from($portfolio->cash)->minus(Decimal::from($portfolio->reserved_cash));
            $size = app(RiskSizer::class)->calculate(Decimal::from($value['equity']), $available, $bound, $stop,
                $policy->payload['owner'] + ['cost_schedule' => $cost, 'slippage_bps' => $p['slippage_bps']], instrument: $product + ['asset_class' => 'crypto']);
            $facts = ['valuation' => $value, 'baseline' => $baseline, 'sizing' => $size, 'price_bound' => (string) $bound, 'initial_stop' => (string) $stop];
            if ($size['reason'] !== null) {
                return $record('no_order', $size['reason'], facts: $facts);
            }
            $quantity = Decimal::from($size['quantity']);
        } else {
            $available = Decimal::from($position?->quantity ?? '0')->minus(Decimal::from($position?->reserved_quantity ?? '0'));
            $quantity = InstrumentSizing::down($available, $product['base_increment']);
            $bound = InstrumentSizing::down(PaperFillPrice::calculate('SELL', $quote['bid'], $p['slippage_bps'], 18), $product['price_increment']);
            $facts = ['available_quantity' => (string) $available, 'price_bound' => (string) $bound];
            if ($quantity->compareTo(Decimal::from('0')) <= 0 || $quantity->compareTo(Decimal::from($product['base_min_size'])) < 0) {
                return $record('no_order', 'exit_quantity_below_venue_minimum', facts: $facts);
            }
            if ($bound->compareTo(Decimal::from('0')) <= 0) {
                return $record('no_order', 'exit_price_bound_invalid', facts: $facts);
            }
        }
        $intent = ['request_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'crypto-paper-order:'.$signal->id)->toString(), 'symbol' => $signal->symbol,
            'side' => $signal->side, 'sleeve' => 'active', 'type' => 'limit', 'quantity' => (string) $quantity, 'price_bound' => (string) $bound,
            'expires_at' => $cutoff->addSeconds($policy->payload['execution']['order_ttl_seconds'])->toISOString(), 'strategy_signal_id' => $signal->id,
            'instrument_policy_id' => $policy->id, 'origin' => 'real_data_paper_simulation', 'asset_class' => 'crypto', 'venue' => 'coinbase',
            'ledger_version' => $portfolio->ledger_version, 'rationale' => 'Deterministic '.$signal->rule_version.': '.$signal->reason];
        $order = SimulationOrder::create(['paper_portfolio_id' => $portfolio->id, 'policy_application_id' => null, 'instrument_policy_id' => $policy->id,
            'actor_id' => $portfolio->actor_id, 'request_id' => $intent['request_id'], 'intent_hash' => hash('sha256', json_encode([$policy->id, $intent, $assumptions], JSON_THROW_ON_ERROR)),
            'execution_version_id' => null, 'intent' => $intent, 'assumptions' => $assumptions, 'auth_generation' => 0,
            'symbol' => $signal->symbol, 'sleeve' => 'active', 'side' => $signal->side, 'type' => 'limit', 'status' => 'open',
            'quantity' => (string) $quantity, 'filled_quantity' => '0', 'reserved_cash' => '0', 'price_bound' => (string) $bound,
            'accepted_at' => now(), 'expires_at' => CarbonImmutable::parse($intent['expires_at'])]);
        if ($order->side === 'BUY') {
            $reserve = $this->ledger->commitment($order, $quantity);
            if ($reserve->compareTo(Decimal::from($portfolio->cash)->minus(Decimal::from($portfolio->reserved_cash))) > 0) {
                $order->update(['status' => 'rejected']);
                $this->ledger->event($portfolio, $order, 'accepted', 'rejected', 'insufficient_unreserved_cash', $facts);

                return $record('rejected', 'insufficient_unreserved_cash', $order, $facts);
            }
            $portfolio->reserved_cash = (string) Decimal::from($portfolio->reserved_cash)->plus($reserve);
            $order->reserved_cash = (string) $reserve;
            $order->save();
        } else {
            $held = $this->ledger->position($portfolio, $order);
            $held->reserved_quantity = (string) Decimal::from($held->reserved_quantity)->plus($quantity);
            $held->save();
        }
        $portfolio->ledger_version++;
        $portfolio->save();
        $this->ledger->event($portfolio, $order, 'accepted', 'accepted', null, $facts);
        // The submission may use the quote that produced the signal; later attempts need a newer observation.
        $stored = $this->valuation->quote($signal->symbol, $cutoff, $p['quote_max_age_seconds']);
        if ($stored && $stored->id === $evidence['input_ids']['quote_id']) {
            $this->attempt($portfolio, $order, $stored, $cutoff, true);
        }

        return $record('order_created', null, $order, $facts);
    }

    /** One fill attempt against one stored quote. Displayed size is consumed at most once per observation and side. */
    private function attempt(Portfolio $portfolio, SimulationOrder $order, object $quote, CarbonImmutable $cutoff, bool $atSubmission): bool
    {
        $data = ['key' => 'coinbase-quote:'.$quote->id, 'symbol' => $quote->product_id,
            'bid' => CryptoMarketCollector::plain((string) $quote->bid), 'ask' => CryptoMarketCollector::plain((string) $quote->ask),
            'bid_size' => CryptoMarketCollector::plain((string) $quote->bid_size), 'ask_size' => CryptoMarketCollector::plain((string) $quote->ask_size),
            'source_at' => CarbonImmutable::parse($quote->source_at)->toISOString(), 'retrieved_at' => CarbonImmutable::parse($quote->retrieved_at)->toISOString(),
            'provenance' => ['venue' => 'coinbase', 'read_id' => $quote->mcp_crypto_market_read_id, 'quote_id' => $quote->id]];
        abort_unless($data['symbol'] === $order->symbol, 422, 'Observation does not match order symbol.');
        $observation = $this->ledger->observation($portfolio, $data, hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), hash('sha256', $data['key']));
        $eventKey = 'observation:'.$observation->id;
        if (SimulationEvent::where('sim_order_id', $order->id)->where('event_key', $eventKey)->exists()) {
            return false;
        }
        $a = $order->assumptions;
        $source = CarbonImmutable::parse($data['source_at']);
        $buy = $order->side === 'BUY';
        $reason = match (true) {
            $source->lt($cutoff->subSeconds($a['quote_max_age_seconds'])) => 'quote_stale',
            ! $atSubmission && $source->lte($this->watermark($order)) => 'observation_not_after_order',
            default => null,
        };
        $price = PaperFillPrice::calculate($order->side, $data[$buy ? 'ask' : 'bid'], $a['slippage_bps'], 18);
        if (! $reason && ($buy ? $price->compareTo(Decimal::from($order->price_bound)) > 0 : $price->compareTo(Decimal::from($order->price_bound)) < 0)) {
            $reason = 'limit_not_marketable';
        }
        $quantity = Decimal::from('0');
        if (! $reason) {
            $used = Decimal::from('0');
            $sameSide = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('side', $order->side)->pluck('id');
            foreach (SimulationEvent::where('observation_id', $observation->id)->whereIn('sim_order_id', $sameSide)->where('kind', 'fill')->get() as $fill) {
                $used = $used->plus(Decimal::from($fill->quantity));
            }
            $remaining = Decimal::from($order->quantity)->minus(Decimal::from($order->filled_quantity));
            $displayed = Decimal::from($data[$buy ? 'ask_size' : 'bid_size'])->minus($used);
            $quantity = InstrumentSizing::down($remaining->compareTo($displayed) < 0 ? $remaining : $displayed, $a['base_increment']);
            if ($quantity->compareTo(Decimal::from('0')) <= 0) {
                $reason = 'liquidity_exhausted';
            }
        }
        $evaluation = ['observation' => $data, 'at_submission' => $atSubmission, 'cutoff' => $cutoff->toISOString()];
        if ($reason) {
            if (! $atSubmission && $source->gt($this->watermark($order))) {
                $order->update(['last_source_at' => $source]);
            }
            $this->ledger->event($portfolio, $order, $eventKey, 'unfilled', $reason, $evaluation, $observation);

            return false;
        }
        $this->ledger->fill($portfolio, $order, $quantity, $price, $observation, $eventKey, $evaluation);

        return true;
    }

    /** Later attempts need a quote newer than both acceptance and the last quote already evaluated. */
    private function watermark(SimulationOrder $order): CarbonImmutable
    {
        return $order->last_source_at !== null && $order->last_source_at->gt($order->accepted_at) ? $order->last_source_at : $order->accepted_at;
    }
}
