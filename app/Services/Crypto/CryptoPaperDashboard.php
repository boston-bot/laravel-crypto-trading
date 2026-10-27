<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Models\PaperTrading\CryptoFeeObservation;
use App\Models\PaperTrading\InstrumentPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategyReplayRun;
use App\Models\PaperTrading\StrategySignal;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read model only: stored crypto forward-paper state and retrospective replays. Never collection, evaluation, orders or policy writes. */
final class CryptoPaperDashboard
{
    public const TARGET_TRADES = 30;

    public const TARGET_DAYS = 28;

    public function read(int $actor, CarbonImmutable $asOf): ?array
    {
        $portfolio = Portfolio::where(['actor_id' => $actor, 'asset_class' => 'crypto', 'venue' => 'coinbase'])->whereNull('mcp_connection_id')->first();
        if (! $portfolio) {
            return null;
        }
        $policy = InstrumentPolicy::where('paper_portfolio_id', $portfolio->id)->latest('version')->firstOrFail();
        $p = $policy->payload['parameters']['values'];
        $valuation = app(CryptoPaperValuation::class);
        $value = $valuation->value($portfolio, $asOf, $p['quote_max_age_seconds']);
        $scales = $this->scales();
        $orders = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->orderBy('id')->get();
        $fills = SimulationEvent::where('kind', 'fill')->whereIn('sim_order_id', $orders->pluck('id'))->where('recorded_at', '<=', $asOf->format('Y-m-d H:i:s.u'))->orderBy('id')->get();
        $trades = $this->trades($orders->keyBy('id'), $fills);
        $positions = $this->positions($portfolio, $value, $scales);
        $basis = array_reduce($positions, fn (Decimal $c, $row) => $c->plus(Decimal::from($row['basis'])), Decimal::from('0'));
        $realized = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->get()->reduce(fn (Decimal $c, $row) => $c->plus(Decimal::from($row->realized_pnl)), Decimal::from('0'));
        $fees = $fills->reduce(fn (Decimal $c, $fill) => $c->plus(Decimal::from($fill->fee)), Decimal::from('0'));
        $start = Decimal::from($portfolio->starting_cash);
        $equity = $value['equity'];
        $curve = $this->curve($portfolio, $orders->keyBy('id'), $fills, $asOf, $equity);
        $since = $portfolio->created_at->utc()->startOfDay();
        $latest = StrategyCycle::where(['paper_portfolio_id' => $portfolio->id, 'asset_class' => 'crypto', 'rule_version' => CryptoStrategyEvaluation::VERSION])
            ->where('recorded_at', '<=', $asOf->format('Y-m-d H:i:s.u'))->latest('id')->first();

        return ['portfolio' => $portfolio, 'policy' => ['version' => $policy->version, 'contract' => $policy->payload['version'], 'owner' => $policy->payload['owner'],
            'fee_review' => $policy->payload['fee_review'] ?? null, 'execution' => $policy->payload['execution'] ?? null, 'parameters' => $policy->payload['parameters']['version']],
            'switches' => ['collection' => (bool) config('crypto.market_data_enabled'), 'evaluation' => (bool) config('crypto.strategy_evaluation_enabled'),
                'paper' => (bool) config('crypto.paper_enabled'), 'fee_refresh' => (bool) config('crypto.fee_refresh_enabled'),
                'coinbase_writes' => (bool) config('broker.coinbase.writes_enabled'), 'robinhood_writes' => (bool) config('broker.robinhood.writes_enabled')],
            'current' => ['equity' => $equity, 'cash' => $value['cash'], 'reserved' => $value['reserved_cash'],
                'available' => (string) Decimal::from($value['cash'])->minus(Decimal::from($value['reserved_cash'])), 'realized' => (string) $realized,
                'unrealized' => $equity === null ? null : (string) Decimal::from($equity)->minus(Decimal::from($value['cash']))->minus($basis), 'fees' => (string) $fees,
                'starting' => (string) $start, 'return_pct' => $equity === null ? null : (string) Decimal::from($equity)->minus($start)->multipliedBy(Decimal::from('100'))->dividedBy($start, 2),
                'max_drawdown_pct' => $curve['max_drawdown_pct'], 'missing_marks' => $value['missing_marks']],
            'cycle' => $latest === null ? null : ['at' => $latest->cycle_at, 'recorded_at' => $latest->recorded_at, 'entry_pause' => $latest->payload['entry_pause'],
                'baseline' => $latest->payload['baseline']['status'] ?? null, 'baseline_reason' => $latest->payload['baseline']['reason'] ?? null,
                'baseline_equity' => $latest->payload['baseline']['equity'] ?? null, 'latched' => $latest->payload['loss_pause_latched'] ?? false,
                'occupied' => $latest->payload['occupied_positions'] ?? null, 'products' => count($latest->payload['products'] ?? []),
                'signals' => StrategySignal::where('cycle_id', $latest->id)->orderBy('symbol')->get(['symbol', 'status', 'side', 'reason'])->all()],
            'activity' => $this->activity($portfolio, $asOf), 'positions' => $positions, 'orders' => $this->orders($orders, $fills, $scales),
            'trades' => array_reverse($trades), 'stats' => $this->stats($trades), 'curve' => $curve,
            // Regime playbooks trade a few times a year; a closed-trade count is not their observation target.
            'progress' => ['closed_trades' => count($trades), 'target_trades' => CryptoParameters::regime($policy->payload['parameters']) ? null : self::TARGET_TRADES,
                'days' => (int) $since->diffInDays($asOf->utc()->startOfDay()), 'target_days' => self::TARGET_DAYS],
            'benchmarks' => $this->benchmarks($policy, $since, $asOf, $p),
            'fees_observation' => CryptoFeeObservation::where(['venue' => 'coinbase', 'actor_id' => $actor])->where('status', 'complete')->latest('retrieved_at')->first(),
            'replay' => StrategyReplayRun::where(['paper_portfolio_id' => $portfolio->id, 'asset_class' => 'crypto'])->where('status', 'complete')->latest('id')->first()];
    }

    /** Decimal places of each product's latest price increment, for display only. */
    private function scales(): array
    {
        $scales = [];
        foreach (DB::table('mcp_crypto_product_snapshots')->where('venue', 'coinbase')->orderBy('id')->get(['product_id', 'price_increment']) as $row) {
            $increment = CryptoMarketCollector::plain((string) $row->price_increment);
            $scales[$row->product_id] = str_contains($increment, '.') ? strlen(explode('.', $increment)[1]) : 0;
        }

        return $scales;
    }

    private function positions(Portfolio $portfolio, array $value, array $scales): array
    {
        $rows = [];
        foreach (SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('quantity', '>', 0)->orderBy('symbol')->get() as $position) {
            $quantity = Decimal::from($position->quantity);
            $mark = $value['marks'][$position->symbol] ?? null;
            $marketValue = $mark === null ? null : $quantity->multipliedBy(Decimal::from($mark['bid']));
            $context = StrategySignal::where(['paper_portfolio_id' => $portfolio->id, 'symbol' => $position->symbol])->latest('id')->first()?->payload['evidence']['report']['position']['context'] ?? null;
            $rows[] = ['symbol' => $position->symbol, 'quantity' => (string) $quantity, 'reserved' => (string) Decimal::from($position->reserved_quantity),
                'basis' => (string) Decimal::from($position->cost_basis), 'average_cost' => (string) Decimal::from($position->cost_basis)->dividedBy($quantity, 18),
                'mark' => $mark['bid'] ?? null, 'mark_at' => $mark['source_at'] ?? null, 'value' => $marketValue === null ? null : (string) $marketValue,
                'unrealized' => $marketValue === null ? null : (string) $marketValue->minus(Decimal::from($position->cost_basis)),
                'entry_at' => $context['entry_at'] ?? null, 'initial_stop' => $context['initial_stop'] ?? null, 'trailing_stop' => $context['trailing_stop'] ?? null,
                'days_held' => $context['sessions_held'] ?? null, 'scale' => $scales[$position->symbol] ?? 8];
        }

        return $rows;
    }

    /** Completed round trips from the simulated fills: flat to flat per product, net of every recorded fee. */
    private function trades($orders, $fills): array
    {
        $open = $trades = [];
        foreach ($fills as $fill) {
            $order = $orders[$fill->sim_order_id];
            $symbol = $order->symbol;
            $quantity = Decimal::from($fill->quantity);
            $notional = $quantity->multipliedBy(Decimal::from($fill->price));
            $episode = $open[$symbol] ?? ['quantity' => Decimal::from('0'), 'cost' => Decimal::from('0'), 'proceeds' => Decimal::from('0'), 'entry_at' => $fill->recorded_at, 'exit_order' => null];
            if ($order->side === 'BUY') {
                $episode['quantity'] = $episode['quantity']->plus($quantity);
                $episode['cost'] = $episode['cost']->plus($notional)->plus(Decimal::from($fill->fee));
            } else {
                $episode['quantity'] = $episode['quantity']->minus($quantity);
                $episode['proceeds'] = $episode['proceeds']->plus($notional)->minus(Decimal::from($fill->fee));
                $episode['exit_order'] = $order;
            }
            $open[$symbol] = $episode;
            if ($episode['quantity']->compareTo(Decimal::from('0')) === 0 && $episode['exit_order'] !== null) {
                $net = $episode['proceeds']->minus($episode['cost']);
                $signal = StrategySignal::find($episode['exit_order']->intent['strategy_signal_id'] ?? 0);
                $trades[] = ['symbol' => $symbol, 'entry_at' => $episode['entry_at'], 'exit_at' => $fill->recorded_at, 'cost' => (string) $episode['cost'],
                    'proceeds' => (string) $episode['proceeds'], 'net' => (string) $net, 'reason' => $signal?->reason,
                    'return_pct' => $episode['cost']->compareTo(Decimal::from('0')) > 0 ? (string) $net->multipliedBy(Decimal::from('100'))->dividedBy($episode['cost'], 2) : null];
                unset($open[$symbol]);
            }
        }

        return $trades;
    }

    private function stats(array $trades): array
    {
        $wins = array_values(array_filter($trades, fn ($t) => Decimal::from($t['net'])->compareTo(Decimal::from('0')) > 0));
        $losses = array_values(array_filter($trades, fn ($t) => Decimal::from($t['net'])->compareTo(Decimal::from('0')) <= 0));
        $sum = fn (array $rows) => array_reduce($rows, fn (Decimal $c, $t) => $c->plus(Decimal::from($t['net'])), Decimal::from('0'));
        $average = fn (array $rows) => $rows === [] ? null : (string) array_reduce($rows, fn (Decimal $c, $t) => $c->plus(Decimal::from($t['return_pct'] ?? '0')), Decimal::from('0'))
            ->dividedBy(Decimal::from((string) count($rows)), 2);
        $lost = Decimal::from('0')->minus($sum($losses));

        return ['closed' => count($trades), 'wins' => count($wins), 'losses' => count($losses),
            'hit_rate_pct' => $trades === [] ? null : (string) Decimal::from((string) count($wins))->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from((string) count($trades)), 2),
            'average_win_pct' => $average($wins), 'average_loss_pct' => $average($losses), 'net' => (string) $sum($trades),
            'profit_factor' => $lost->compareTo(Decimal::from('0')) === 0 ? null : (string) $sum($wins)->dividedBy($lost, 2)];
    }

    /**
     * Equity at each UTC day close since the sleeve opened: ledger cash and fills recorded by the close, holdings marked at the
     * stored daily close. A day without a needed close is unknown. Today's point is the current marked equity.
     */
    private function curve(Portfolio $portfolio, $orders, $fills, CarbonImmutable $asOf, ?string $current): array
    {
        $points = [];
        $entries = DB::table('mcp_paper_cash_entries')->where('paper_portfolio_id', $portfolio->id)->orderBy('recorded_at')->orderBy('id')->get(['balance_after', 'recorded_at']);
        $today = $asOf->utc()->startOfDay();
        for ($day = $portfolio->created_at->utc()->startOfDay(); $day->lt($today); $day = $day->addDay()) {
            $close = $day->addDay();
            $entry = $entries->filter(fn ($row) => CarbonImmutable::parse($row->recorded_at)->lte($close))->last();
            $equity = $entry === null ? null : Decimal::from(CryptoMarketCollector::plain((string) $entry->balance_after));
            $held = [];
            foreach ($fills->filter(fn ($fill) => $fill->recorded_at->lte($close)) as $fill) {
                $order = $orders[$fill->sim_order_id];
                $held[$order->symbol] = Decimal::from($held[$order->symbol] ?? '0')->plus(Decimal::from($fill->quantity)->multipliedBy(Decimal::from($order->side === 'BUY' ? '1' : '-1')));
            }
            foreach ($held as $product => $quantity) {
                if ($equity === null || $quantity->compareTo(Decimal::from('0')) === 0) {
                    continue;
                }
                $candle = DB::table('mcp_crypto_candles')->where(['venue' => 'coinbase', 'product_id' => $product, 'granularity' => 'ONE_DAY'])
                    ->where('starts_at', CryptoMarketCollector::time($day))->first(['close']);
                $equity = $candle === null ? null : $equity->plus($quantity->multipliedBy(Decimal::from(CryptoMarketCollector::plain((string) $candle->close))));
            }
            $points[] = ['date' => $day->toDateString(), 'equity' => $equity === null ? null : (string) $equity, 'kind' => 'daily_close'];
        }
        $points[] = ['date' => $today->toDateString(), 'equity' => $current, 'kind' => 'current'];
        $peak = null;
        $drawdown = Decimal::from('0');
        foreach ($points as $point) {
            if ($point['equity'] === null) {
                continue;
            }
            $equity = Decimal::from($point['equity']);
            $peak = $peak === null || $equity->compareTo($peak) > 0 ? $equity : $peak;
            $fall = $peak->minus($equity)->multipliedBy(Decimal::from('100'))->dividedBy($peak, 2);
            $drawdown = $fall->compareTo($drawdown) > 0 ? $fall : $drawdown;
        }
        $known = array_values(array_filter($points, fn ($p) => $p['equity'] !== null));

        return ['points' => $points, 'known' => count($known), 'max_drawdown_pct' => $known === [] ? null : (string) $drawdown, 'chart' => $this->chart($points)];
    }

    /** SVG coordinates for known points; lines break at unknown days. */
    private function chart(array $points): array
    {
        $known = array_filter($points, fn ($p) => $p['equity'] !== null);
        if (count($known) < 2) {
            return ['segments' => [], 'dots' => [], 'min' => null, 'max' => null];
        }
        $values = array_map(fn ($p) => Decimal::from($p['equity']), $known);
        $min = array_reduce($values, fn ($c, $v) => $c === null || $v->compareTo($c) < 0 ? $v : $c);
        $max = array_reduce($values, fn ($c, $v) => $c === null || $v->compareTo($c) > 0 ? $v : $c);
        $span = $max->minus($min)->compareTo(Decimal::from('0')) === 0 ? Decimal::from('1') : $max->minus($min);
        $last = max(1, count($points) - 1);
        $segments = $dots = [];
        $segment = [];
        foreach ($points as $i => $point) {
            if ($point['equity'] === null) {
                if ($segment !== []) {
                    $segments[] = implode(' ', $segment);
                }
                $segment = [];

                continue;
            }
            $x = (string) Decimal::from((string) (30 + intdiv(900 * $i, $last)));
            $y = (string) Decimal::from('195')->minus(Decimal::from($point['equity'])->minus($min)->multipliedBy(Decimal::from('170'))->dividedBy($span, 1));
            $segment[] = $x.','.$y;
            $dots[] = ['x' => $x, 'y' => $y, 'date' => $point['date'], 'equity' => $point['equity']];
        }
        if ($segment !== []) {
            $segments[] = implode(' ', $segment);
        }

        return ['segments' => $segments, 'dots' => $dots, 'min' => (string) $min, 'max' => (string) $max];
    }

    /** Evaluations over the trailing 24 hours: how many cycles ran and what kept entries waiting. */
    private function activity(Portfolio $portfolio, CarbonImmutable $asOf): array
    {
        $cycles = StrategyCycle::where(['paper_portfolio_id' => $portfolio->id, 'asset_class' => 'crypto', 'rule_version' => CryptoStrategyEvaluation::VERSION])
            ->where('cycle_at', '>', $asOf->subDay()->format('Y-m-d H:i:s.u'))->where('recorded_at', '<=', $asOf->format('Y-m-d H:i:s.u'));
        $signals = StrategySignal::whereIn('cycle_id', (clone $cycles)->select('id'));
        $counts = (clone $signals)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();
        $total = array_sum($counts);
        $reasons = (clone $signals)->where('status', '!=', 'signal')->selectRaw('reason, COUNT(*) AS total')->groupBy('reason')->orderByDesc('total')->orderBy('reason')->limit(8)->get()
            ->map(fn ($r) => ['reason' => $r->reason, 'count' => (int) $r->total, 'width' => $total ? (string) Decimal::from((string) $r->total)->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from((string) $total), 2) : '0'])->all();

        return ['cycles' => $cycles->count(), 'expected_cycles' => 288, 'evaluations' => $total, 'counts' => $counts, 'reasons' => $reasons,
            'unavailable_pct' => $total ? (string) Decimal::from((string) ($counts['unavailable'] ?? 0))->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from((string) $total), 2) : null];
    }

    private function orders($orders, $fills, array $scales): array
    {
        return $orders->sortByDesc('id')->take(25)->map(function ($order) use ($fills, $scales) {
            $own = $fills->where('sim_order_id', $order->id);
            $quantity = $own->reduce(fn (Decimal $c, $f) => $c->plus(Decimal::from($f->quantity)), Decimal::from('0'));
            $notional = $own->reduce(fn (Decimal $c, $f) => $c->plus(Decimal::from($f->quantity)->multipliedBy(Decimal::from($f->price))), Decimal::from('0'));
            $closed = SimulationEvent::where('sim_order_id', $order->id)->where('event_key', 'closed')->first();

            return ['symbol' => $order->symbol, 'side' => $order->side, 'status' => $order->status, 'quantity' => (string) Decimal::from($order->quantity),
                'filled' => (string) $quantity, 'bound' => (string) Decimal::from($order->price_bound), 'accepted_at' => $order->accepted_at, 'expires_at' => $order->expires_at,
                'average' => $quantity->compareTo(Decimal::from('0')) > 0 ? (string) $notional->dividedBy($quantity, 18) : null,
                'fees' => (string) $own->reduce(fn (Decimal $c, $f) => $c->plus(Decimal::from($f->fee)), Decimal::from('0')), 'reason' => $closed?->reason,
                'scale' => $scales[$order->symbol] ?? 8];
        })->values()->all();
    }

    /** Buy-and-hold from the sleeve's first UTC day open to the current bid: BTC, and an equal weight of the collection list. */
    private function benchmarks(InstrumentPolicy $policy, CarbonImmutable $since, CarbonImmutable $asOf, array $p): array
    {
        $change = function (string $product) use ($since, $asOf, $p): ?Decimal {
            $open = DB::table('mcp_crypto_candles')->where(['venue' => 'coinbase', 'product_id' => $product, 'granularity' => 'ONE_DAY'])->where('starts_at', CryptoMarketCollector::time($since))->value('open');
            $quote = app(CryptoPaperValuation::class)->quote($product, $asOf, $p['quote_max_age_seconds']);
            if ($open === null || $quote === null) {
                return null;
            }

            return Decimal::from(CryptoMarketCollector::plain((string) $quote->bid))->dividedBy(Decimal::from(CryptoMarketCollector::plain((string) $open)), 8)
                ->minus(Decimal::from('1'))->multipliedBy(Decimal::from('100'));
        };
        $each = array_filter(array_map($change, config('crypto.coinbase_products') ?: []));
        $btc = $change($p['benchmark_symbol']);

        return ['since' => $since->toDateString(), 'btc_pct' => $btc === null ? null : (string) $btc->rounded(2, RoundingMode::HALF_EVEN),
            'equal_weight_pct' => $each === [] ? null : (string) array_reduce($each, fn (Decimal $c, Decimal $v) => $c->plus($v), Decimal::from('0'))->dividedBy(Decimal::from((string) count($each)), 2),
            'products' => count($each)];
    }
}
