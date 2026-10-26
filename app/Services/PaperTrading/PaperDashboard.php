<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\Monitoring\MarketSession;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SessionValuation;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/** Read model only: stored forward-paper state, never collection, evaluation, order processing or policy writes. */
final class PaperDashboard
{
    public function read(McpConnection $connection, string $date, CarbonImmutable $asOf, ?string $symbol = null, ?string $state = null): ?array
    {
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
        if (! $portfolio) {
            return null;
        }
        $stamp = $asOf->utc()->format('Y-m-d H:i:s.u');
        $start = CarbonImmutable::parse($date, 'America/New_York')->startOfDay()->utc();
        $end = $start->setTimezone('America/New_York')->addDay()->utc();
        $cycles = StrategyCycle::where('position_source', 'paper')->where('mcp_connection_id', $connection->id)->where('paper_portfolio_id', $portfolio->id)->where('scope', $portfolio->scope)
            ->where('auth_generation', $connection->auth_generation)->where('recorded_at', '<=', $stamp);
        $sessionCycles = (clone $cycles)->where('cycle_at', '>=', $start)->where('cycle_at', '<', $end);
        $signals = StrategySignal::where('position_source', 'paper')->where('paper_portfolio_id', $portfolio->id)->where('mcp_connection_id', $connection->id)
            ->whereIn('cycle_id', (clone $sessionCycles)->select('id'))->where('cutoff', '<=', $stamp)->where('recorded_at', '<=', $stamp);
        $counts = (clone $signals)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all();
        $reasons = (clone $signals)->selectRaw('reason, COUNT(*) AS total')->where('status', '!=', 'signal')->groupBy('reason')->orderByDesc('total')->orderBy('reason')->get();
        $total = array_sum($counts);
        $bars = $reasons->take(8)->map(fn ($r) => ['reason' => $r->reason, 'count' => (int) $r->total,
            'width' => $total ? (string) Decimal::from((string) $r->total)->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from((string) $total), 2) : '0'])->all();
        $positions = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->orderBy('symbol')->orderBy('sleeve')->get();
        $positionRows = [];
        $equity = Decimal::from($portfolio->cash)->plus(app(PaperIncome::class)->receivable($portfolio));
        $realized = $unrealized = Decimal::from('0');
        $validMarks = true;
        $execution = ExecutionPolicies::current($portfolio);
        foreach ($positions as $position) {
            $realized = $realized->plus(Decimal::from($position->realized_pnl));
            $quantity = Decimal::from($position->quantity);
            if ($quantity->compareTo(Decimal::from('0')) === 0) {
                continue;
            }
            $input = app(PaperMarketInputs::class)->latest($connection, $position->symbol, $asOf);
            $age = $execution?->payload['quote_max_age_seconds'] ?? 20;
            $quote = StoredStrategyQuote::read($input, $connection, $asOf, $age);
            $clear = $input && app(CorporateActionClearance::class)->inspect($input, $asOf, $age)['clear'] === true;
            $usable = $quote !== null && $clear && $position->blocked_reason === null && $quantity->compareTo(Decimal::from('0')) > 0;
            $value = $usable ? $quantity->multipliedBy(Decimal::from($quote['bid'])) : null;
            $pnl = $value?->minus(Decimal::from($position->cost_basis));
            if ($value === null) {
                $validMarks = false;
            } else {
                $equity = $equity->plus($value);
                $unrealized = $unrealized->plus($pnl);
            }
            $facts = app(SignalPositionFacts::class)->inspect($portfolio, $position->symbol, $asOf);
            $latest = StrategySignal::whereIn('cycle_id', (clone $cycles)->select('id'))->where('symbol', $position->symbol)->where('cutoff', '<=', $stamp)
                ->where('recorded_at', '<=', $stamp)->latest('id')->first();
            $context = $latest?->payload['evidence']['report']['position']['context'] ?? null;
            $trail = $context && ($context['entry_order_id'] ?? null) === ($facts['context']['entry_order_id'] ?? null) ? ($context['trailing_stop'] ?? null) : null;
            $positionRows[] = ['symbol' => $position->symbol, 'sleeve' => $position->sleeve, 'quantity' => $position->quantity,
                'reserved' => $position->reserved_quantity, 'basis' => $position->cost_basis,
                'average_cost' => $quantity->compareTo(Decimal::from('0')) > 0 ? (string) Decimal::from($position->cost_basis)->dividedBy($quantity, 18) : null,
                'mark' => $usable ? $quote['bid'] : null, 'mark_at' => $usable ? $quote['bid_as_of'] : null,
                'value' => $value === null ? null : (string) $value, 'unrealized' => $pnl === null ? null : (string) $pnl,
                'initial_stop' => $facts['context']['initial_stop'] ?? null, 'trailing_stop' => $trail,
                'reason' => $position->blocked_reason ?? ($usable ? null : 'fresh_quote_or_action_clearance_unavailable'),
                'managed' => $facts['context'] !== null];
        }
        $allOrders = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('accepted_at', '<=', $stamp);
        $sessionOrders = (clone $allOrders)->where('accepted_at', '>=', $start)->where('accepted_at', '<', $end);
        $fees = Decimal::from('0');
        foreach (SimulationEvent::whereIn('sim_order_id', (clone $allOrders)->select('id'))->where('kind', 'fill')->where('recorded_at', '<=', $stamp)->cursor() as $fill) {
            $fees = $fees->plus(Decimal::from($fill->fee));
        }
        $orderPage = (clone $sessionOrders)->when($symbol, fn ($q) => $q->where('symbol', $symbol))->when($state, fn ($q) => $q->where('status', $state))
            ->latest('id')->paginate(20, ['*'], 'orders_page')->withQueryString();
        $fills = SimulationEvent::whereIn('sim_order_id', $orderPage->pluck('id'))->where('kind', 'fill')->where('recorded_at', '<=', $stamp)->orderBy('id')->get()->groupBy('sim_order_id');
        $orderPage->setCollection($orderPage->getCollection()->map(function ($order) use ($fills) {
            $events = $fills[$order->id] ?? collect();
            $quantity = $notional = $fee = Decimal::from('0');
            foreach ($events as $fill) {
                $q = Decimal::from($fill->quantity);
                $quantity = $quantity->plus($q);
                $notional = $notional->plus($q->multipliedBy(Decimal::from($fill->price)));
                $fee = $fee->plus(Decimal::from($fill->fee));
            }

            return ['order' => $order, 'fills' => $events, 'quantity' => (string) $quantity, 'fees' => (string) $fee,
                'average' => $quantity->compareTo(Decimal::from('0')) > 0 ? (string) $notional->dividedBy($quantity, 18) : null];
        }));
        $signalPage = (clone $signals)->when($symbol, fn ($q) => $q->where('symbol', $symbol))->latest('id')->paginate(20, ['*'], 'signals_page')->withQueryString();
        $membership = app(StrategyWatchlists::class)->nominations($connection, $asOf);
        $nominees = app(SymbolUniverse::class)->candidates($connection, $asOf);
        $universe = app(SymbolUniverse::class)->reads($connection, $asOf);
        $readyPage = max(1, (int) request()->query('readiness_page', 1));
        $selectedSymbols = $symbol ? array_values(array_intersect($universe, [$symbol])) : $universe;
        $readyRows = [];
        foreach (array_slice($selectedSymbols, ($readyPage - 1) * 20, 20) as $ticker) {
            $profile = app(SymbolProfiles::class)->latest($connection, $ticker, $asOf);
            $classification = app(PaperInstrumentClassifications::class)->latestComplete($connection->id, $portfolio->scope, $ticker, $asOf);
            $classificationCurrent = $classification && $classification->retrieved_at->gte($asOf->subDays(config('sec_edgar.classification_max_age_days', 7)));
            $history = app(PaperMarketReferences::class)->verifiedHistory($connection, $ticker, $asOf);
            $listing = app(PaperMarketReferences::class)->listing($connection, $ticker, $asOf);
            $readyRows[] = ['symbol' => $ticker, 'role' => in_array($ticker, $nominees, true) ? 'Nominated' : (in_array($ticker, $membership['data_symbols'] ?? [], true) ? 'Data only' : 'Held'),
                'history' => $history['read'] ? 'Recorded' : 'Missing / stale', 'listing' => $listing['status'],
                'classification' => $classificationCurrent ? $classification->basis : 'Missing / stale',
                'profile' => $profile?->status ?? 'missing', 'profile_at' => $profile?->recorded_at,
                'profile_reason' => $profile?->reason];
        }
        $readiness = new LengthAwarePaginator($readyRows, count($selectedSymbols), 20, $readyPage,
            ['path' => request()->url(), 'pageName' => 'readiness_page', 'query' => request()->except('readiness_page')]);
        $current = ['cash' => $portfolio->cash, 'reserved' => $portfolio->reserved_cash,
            'available' => (string) Decimal::from($portfolio->cash)->minus(Decimal::from($portfolio->reserved_cash)),
            'equity' => $validMarks ? (string) $equity : null, 'realized' => (string) $realized,
            'unrealized' => $validMarks ? (string) $unrealized : null, 'fees' => (string) $fees,
            'receivable' => (string) app(PaperIncome::class)->receivable($portfolio), 'dividends' => (string) app(PaperIncome::class)->received($portfolio)];

        return ['portfolio' => $portfolio, 'current' => $current, 'positions' => collect($positionRows)->when($symbol, fn ($rows) => $rows->where('symbol', $symbol)),
            'counts' => $counts, 'evaluations' => $total, 'blockers' => $bars, 'nominees' => count($nominees), 'universe' => count($universe),
            'orders_count' => (clone $sessionOrders)->count(), 'open_orders' => (clone $allOrders)->whereIn('status', ['open', 'partial'])->count(),
            'orders' => $orderPage, 'signals' => $signalPage, 'readiness' => $readiness,
            'last_cycle' => (clone $sessionCycles)->latest('cycle_at')->first(), 'membership' => $membership,
            'cadence' => app(CadenceReport::class)->session($connection, $date, $execution?->payload['quote_max_age_seconds'] ?? 20),
            'chart' => $this->chart($connection, $portfolio, $date, $asOf)];
    }

    private function chart(McpConnection $connection, Portfolio $portfolio, string $date, CarbonImmutable $asOf): array
    {
        $stamp = $asOf->utc()->format('Y-m-d H:i:s.u');
        $dates = MarketSession::where('exchange', 'XNYS')->whereIn('status', ['regular', 'early_close'])->where('session_date', '<=', $date)
            ->where('session_date', '>=', $portfolio->created_at->setTimezone('America/New_York')->toDateString())->where('opens_at', '<=', $stamp)
            ->orderByDesc('session_date')->limit(30)->pluck('session_date')->reverse()->values();
        $valuations = SessionValuation::where('paper_portfolio_id', $portfolio->id)->where('auth_generation', $connection->auth_generation)
            ->whereIn('session_date', $dates)->where('cutoff', '<=', $stamp)->where('recorded_at', '<=', $stamp)->get()->keyBy('session_date');
        $series = $dates->map(function ($day) use ($valuations) {
            $row = $valuations[$day] ?? null;
            $valid = $row && $row->rule_version === PaperSessionValuations::VERSION &&
                hash_equals($row->input_fingerprint, hash('sha256', json_encode($row->payload, JSON_THROW_ON_ERROR))) && Decimal::from($row->equity)->compareTo(Decimal::from('0')) > 0;

            return ['date' => $day, 'equity' => $valid ? $row->equity : null, 'reason' => $valid ? null : 'No verified opening valuation'];
        })->all();

        return app(PaperEquityChart::class)->build($series);
    }
}
