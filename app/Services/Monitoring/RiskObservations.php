<?php

namespace App\Services\Monitoring;

use App\Models\McpConnection;
use App\Models\Monitoring\SleeveAllocation;
use App\Models\Monitoring\ThesisVersion;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class RiskObservations
{
    public function __construct(private MarketCalendar $calendar, private InvalidationEvaluator $invalidations) {}

    public function evaluate(McpConnection $connection, array $parts): array
    {
        $zero = Decimal::from('0');
        $pause = ['execution_disabled', 'flow_history_unavailable', 'sector_and_etf_overlap_unknown'];
        $freshness = [];
        foreach (['portfolio', 'positions', 'orders'] as $name) {
            $part = $parts[$name];
            $sourceTimes = array_column($part['records'], 'source_as_of');
            // Empty complete lists have a retrieval time, but no invented source time.
            $known = count($sourceTimes) > 0 && ! in_array(null, $sourceTimes, true);
            $source = $known ? collect($sourceTimes)->sortBy(fn ($time) => CarbonImmutable::parse($time)->getTimestamp())->first() : null;
            $state = self::freshness($source, $part['retrieved_at']);
            $freshness[$name] = ['source_as_of' => $source, 'retrieved_at' => $part['retrieved_at'], 'status' => $state, 'coverage' => $part['coverage'], 'source' => $part['source']];
            if ($state !== 'fresh') {
                $pause[] = $name.'_'.$state;
            }
        }
        $portfolio = $parts['portfolio']['records'][0]['data'];
        $scope = AccountScope::key($connection);
        $allocations = SleeveAllocation::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('effective_at', '<=', now())->orderBy('id')->get()->groupBy(fn ($row) => $row->payload['instrument_key']);
        $holdings = [];
        $exposure = $zero;
        $exposureKnown = true;
        $sleeves = ['long_term' => $zero, 'active' => $zero, 'unclassified' => $zero];
        $seen = [];
        foreach ($parts['positions']['records'] as $row) {
            $data = $row['data'];
            $key = self::instrumentKey($data);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Duplicate position identity.');
            }
            $seen[$key] = true;
            $quantity = Decimal::from($data['quantity'])->rounded(18, RoundingMode::UNNECESSARY);
            $value = isset($data['market_value']) ? Decimal::from($data['market_value']) : null;
            $quote = $parts['quotes'][$data['symbol'] ?? '']['records'][0] ?? null;
            if ($value === null && isset($quote['data']['last'])) {
                $value = $quantity->multipliedBy(Decimal::from($quote['data']['last']));
            }
            if ($value !== null) {
                $value = $value->rounded(18, RoundingMode::HALF_EVEN);
            }
            $quoteRetrieval = $parts['quotes'][$data['symbol'] ?? '']['retrieved_at'] ?? null;
            $freshness['quote:'.($data['symbol'] ?? $key)] = ['source_as_of' => $quote['source_as_of'] ?? null,
                'retrieved_at' => $quoteRetrieval, 'status' => self::freshness($quote['source_as_of'] ?? null, $quoteRetrieval),
                'attempted_at' => $parts['quote_failures'][$data['symbol'] ?? '']['attempted_at'] ?? null,
                'error_code' => $parts['quote_failures'][$data['symbol'] ?? '']['error_code'] ?? ($quote ? null : 'not_requested_or_no_symbol'),
                'coverage' => 'equity_quote', 'source' => $parts['quotes'][$data['symbol'] ?? '']['source'] ?? 'unavailable'];
            if (! $quote || self::freshness($quote['source_as_of'], $parts['quotes'][$data['symbol']]['retrieved_at']) !== 'fresh') {
                $pause[] = 'quotes_unknown_or_stale';
            }
            $assigned = ['long_term' => $zero, 'active' => $zero];
            foreach ($allocations[$key] ?? [] as $allocation) {
                $a = $allocation->payload;
                $assigned[$a['sleeve']] = Decimal::from($a['quantity']); // latest append-only quantity per sleeve
            }
            $total = $assigned['long_term']->plus($assigned['active']);
            if ($total->compareTo($quantity) > 0) {
                // Broker reductions never silently rewrite the owner's historical allocation.
                $pause[] = 'sleeve_reconciliation_required';
                $assigned = ['long_term' => $zero, 'active' => $zero];
                $total = $zero;
            }
            $assigned['unclassified'] = $quantity->plus($total->multipliedBy(Decimal::from('-1')));
            $holdings[] = ['instrument_key' => $key, 'symbol' => $data['symbol'] ?? null, 'quantity' => (string) $quantity,
                'market_value' => $value === null ? null : (string) $value, 'attribution' => array_map(strval(...), $assigned)];
            if ($assigned['unclassified']->compareTo($zero) > 0) {
                $pause[] = 'unclassified_holdings';
            }
            if ($value === null) {
                $exposureKnown = false;
            } else {
                $exposure = $exposure->plus($value);
                // Allocate value proportionally with explicit rounding; residual keeps totals conserved.
                if ($quantity->compareTo($zero) > 0) {
                    $classified = $zero;
                    foreach (['long_term', 'active'] as $sleeve) {
                        $amount = $value->multipliedBy($assigned[$sleeve])->dividedBy($quantity, 18);
                        $sleeves[$sleeve] = $sleeves[$sleeve]->plus($amount);
                        $classified = $classified->plus($amount);
                    }
                    $sleeves['unclassified'] = $sleeves['unclassified']->plus($value->plus($classified->multipliedBy(Decimal::from('-1'))));
                }
            }
        }
        $pending = $zero;
        $pendingKnown = true;
        $unresolved = 0;
        $pendingBuys = 0;
        foreach ($parts['orders']['records'] as $row) {
            $order = $row['data'];
            if (in_array($order['status'], ['filled', 'cancelled', 'canceled', 'rejected', 'failed', 'expired', 'voided'], true)) {
                continue;
            }
            $unresolved++;
            if (! in_array($order['status'], ['new', 'queued', 'confirmed', 'unconfirmed', 'pending', 'partially_filled'], true)) {
                $pause[] = 'unknown_order_state';
                $pendingKnown = false;
            }
            if ($order['side'] !== 'buy') {
                continue;
            }
            $pendingBuys++;
            if (! isset($order['quantity'], $order['filled_quantity'], $order['price']) || ($order['order_type'] ?? '') !== 'limit') {
                $pendingKnown = false;

                continue;
            }
            $remaining = Decimal::from($order['quantity'])->plus(Decimal::from($order['filled_quantity'])->multipliedBy(Decimal::from('-1')));
            if ($remaining->compareTo($zero) < 0) {
                throw new InvalidArgumentException('Filled quantity exceeds order quantity.');
            }
            $pending = $pending->plus($remaining->multipliedBy(Decimal::from($order['price'])));
        }
        if (! $pendingKnown) {
            $pause[] = 'pending_commitments_unknown';
        }
        if ($pendingBuys > 0) {
            $pause[] = 'pending_fees_unknown';
        }
        $session = $this->calendar->at(CarbonImmutable::now());
        if ($session['status'] === 'unknown') {
            $pause[] = 'market_session_unknown';
        }
        $thesisResults = [];
        $theses = ThesisVersion::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('effective_at', '<=', now())->orderBy('id')->get()->groupBy(fn ($r) => $r->payload['thesis_key']);
        foreach ($theses as $versions) {
            $thesis = $versions->last();
            $quotePart = $parts['quotes'][$thesis->payload['symbol']] ?? null;
            $evidence = $quotePart['records'][0] ?? null;
            $result = $this->invalidations->evaluate($thesis->payload['invalidation'], $evidence, $quotePart['retrieved_at'] ?? null);
            $thesisResults[] = ['version_id' => $thesis->id, 'symbol' => $thesis->payload['symbol'], 'result' => $result];
            if ($result === 'breached') {
                $pause[] = 'thesis_breach';
            }
        }

        if ($reason = app(ExternalOrders::class)->entryReason($connection)) {
            $pause[] = $reason;
        }

        return ['holdings' => $holdings, 'equity' => $portfolio['equity'] ?? null, 'cash' => $portfolio['cash'] ?? null,
            'buying_power' => $portfolio['buying_power'] ?? null, 'equity_exposure' => $exposureKnown ? (string) $exposure : null,
            'sleeve_exposure' => $exposureKnown ? array_map(strval(...), $sleeves) : null,
            'pending_buy_limit_notional' => $pendingKnown ? (string) $pending : null,
            'pending_buy_commitments' => $pendingKnown && $pendingBuys === 0 ? '0' : null,
            'pending_commitment_basis' => 'remaining_limit_notional_excludes_unverified_fees', 'buying_power_basis' => 'broker_reported_not_reduced_again',
            'unresolved_orders' => $unresolved, 'freshness' => $freshness, 'session' => $session, 'theses' => $thesisResults,
            'performance' => ['available' => false, 'reason' => 'verified_flow_history_and_baseline_unavailable', 'return' => null, 'drawdown' => null],
            'pause_reasons' => array_values(array_unique($pause)), 'mode' => 'disabled'];
    }

    public static function instrumentKey(array $data): string
    {
        // A stable symbol fallback is required by the actual equity positions contract, which omits instrument IDs.
        return ($data['symbol'] ?? $data['instrument_id']).':'.($data['position_type'] ?? 'equity');
    }

    public static function freshness(?string $source, ?string $retrieved): string
    {
        if ($retrieved === null) {
            return 'unavailable';
        }
        $retrieval = CarbonImmutable::parse($retrieved);
        if ($retrieval->isFuture() || $retrieval->lt(now()->subMinutes(5))) {
            return 'stale';
        }
        if ($source === null) {
            return 'source_time_unknown';
        }
        $time = CarbonImmutable::parse($source);

        return $time->isFuture() || $time->lt(now()->subMinutes(5)) ? 'stale' : 'fresh';
    }
}
