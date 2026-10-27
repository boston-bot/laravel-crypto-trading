<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Services\Broker\Agentic\RobinhoodOrderContract;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;

/**
 * Retrospective daily replay of the live profile, signal, sizing and cost rules over split-adjusted daily bars.
 * Each day uses only bars completed before it. It is a daily approximation of the five-minute loop, labeled as such:
 * no earnings blackout (dates are not reconstructed), no 15-minute opening delay, no intraday quotes or book
 * liquidity, and today's watchlist applied to the past (selection bias). Pure: no network, storage or model.
 *
 * Orders are whole-share limit orders unless settings select `fractional_market`: Robinhood's only fractional
 * order type, a regular-hours market order. That mode sizes to 6 decimals with the $1 minimum and applies the
 * supplied, more conservative market slippage to entries and exits.
 */
final class StrategyReplay
{
    use IntradayQuoteReplay;
    use MultiSessionQuoteReplay;

    public const MULTISESSION_VERSION = 'intraday_quotes_multisession_v2';

    public const INTRADAY_VERSION = 'intraday_quotes_v2';

    // v3: research order modes and rule switches; the defaults reproduce v2.
    public const VERSION = 'daily_replay_v3';

    /** Bars per profile, matching the live 400-calendar-day history window (about 275 sessions). */
    public const LIVE_WINDOW = 275;

    public function __construct(private ProfileCalculator $profiles, private SignalRules $rules, private RiskSizer $sizer, private TradingCosts $costs) {}

    /**
     * @param  array<string, list<array>>  $bars  symbol => [date, o, h, l, c, v, vw, n] oldest first
     * @param  list<array>  $benchmark  benchmark bars; its dates are the replay's trading sessions
     */
    public function run(array $bars, array $benchmark, array $p, array $settings, string $equity, ?string $startDate = null, ?string $endDate = null): array
    {
        ksort($bars);
        $mode = $settings['order_mode'] ?? 'whole_share_limit';
        if (! in_array($mode, ['whole_share_limit', 'fractional_market', 'crypto_limit'], true) || ($mode === 'fractional_market' && ! RobinhoodOrderContract::allowsFractional('market'))) {
            throw new \InvalidArgumentException('Unsupported replay order mode.');
        }
        // Crypto limit orders use each product's venue increments, the instrument-aware sizer and the live 200-bar window.
        $instruments = $mode === 'crypto_limit' ? $settings['instruments'] : null;
        $window = $instruments === null ? self::LIVE_WINDOW : $settings['profile_window'];
        $scale = $mode === 'fractional_market' ? RobinhoodOrderContract::FRACTIONAL_MAX_DECIMALS : 0;
        // Market fills use their own slippage assumption for entries, exits and planned risk.
        $settings = $mode === 'fractional_market' ? ['slippage_bps' => $settings['market_slippage_bps']] + $settings : $settings;
        $dates = array_column($benchmark, 0);
        $index = [];
        foreach ($bars as $symbol => $series) {
            $index[$symbol] = array_flip(array_column($series, 0));
        }
        $first = max($p['market_filter_sma_sessions'], $p['slow_sma_sessions']);
        // Live crypto evaluation requires a full window, so replay starts only once one exists.
        $first = $instruments === null ? $first : max($first, $window);
        $zero = Decimal::from('0');
        $cash = Decimal::from($equity);
        $positions = [];
        $lastExit = [];
        $lastClose = [];
        $trades = [];
        $curve = [];
        $counts = ['setups' => 0, 'entries' => 0, 'skipped_zero_size' => 0, 'skipped_capacity' => 0, 'skipped_chase_or_no_cross' => 0, 'market_filter_off_days' => 0]
            + ($instruments === null ? [] : ['skipped_liquidity' => 0]);
        $peak = Decimal::from($equity);
        $drawdown = $zero;
        $exposed = 0;
        $sell = function (string $symbol, array $position, string $price, string $reason, string $date, int $i) use (&$cash, &$trades, &$lastExit, $settings, $instruments): void {
            $fill = Decimal::from($price)->multipliedBy(Decimal::from('1')->minus($this->bps($settings['slippage_bps'])));
            $fill = $instruments === null ? $fill->rounded(4, RoundingMode::DOWN) : InstrumentSizing::down($fill, $instruments[$symbol]['price_increment']);
            $quantity = Decimal::from($position['quantity']);
            $fee = $this->costs->fee($settings['cost_schedule'], 'SELL', $quantity, $fill);
            $cash = $cash->plus($quantity->multipliedBy($fill))->minus($fee);
            $cost = $quantity->multipliedBy(Decimal::from($position['entry_price']))->plus(Decimal::from($position['buy_fee']));
            $net = $quantity->multipliedBy($fill)->minus($fee)->minus($cost);
            $trades[] = ['symbol' => $symbol, 'setup' => $position['setup'], 'entry_date' => $position['entry_date'], 'entry_price' => $position['entry_price'],
                'exit_date' => $date, 'exit_price' => (string) $fill, 'quantity' => $position['quantity'], 'reason' => $reason,
                'sessions_held' => $position['sessions_held'], 'net' => (string) $net->rounded(2, RoundingMode::HALF_EVEN),
                'return_pct' => (string) $net->multipliedBy(Decimal::from('100'))->dividedBy($cost, 4)];
            $lastExit[$symbol] = $i;
        };
        for ($i = $first; $i < count($dates); $i++) {
            [$date, $prev] = [$dates[$i], $dates[$i - 1]];
            // Earlier bars only warm the profiles; they never create positions or performance.
            if (($startDate !== null && $date < $startDate) || ($endDate !== null && $date > $endDate)) {
                continue;
            }
            $bench = array_slice($benchmark, max(0, $i - $window), min($i, $window));
            $profiles = [];
            foreach ($bars as $symbol => $series) {
                $at = $index[$symbol][$prev] ?? null;
                $profiles[$symbol] = $at === null ? null
                    : $this->profiles->compute(array_slice($series, max(0, $at - $window + 1), min($at + 1, $window)), $bench, $p, ['status' => 'pass', 'reason' => 'not_reconstructed_in_replay']);
            }
            $marks = $lastClose;
            foreach ($bars as $symbol => $series) {
                if (isset($index[$symbol][$date])) {
                    $marks[$symbol] = $series[$index[$symbol][$date]][1];
                }
            }
            // Only opening exits have known precedence over this session's entries.
            foreach ($positions as $symbol => $position) {
                $bar = isset($index[$symbol][$date]) ? $bars[$symbol][$index[$symbol][$date]] : null;
                if ($bar === null) {
                    continue;
                }
                $profile = ($profiles[$symbol]['status'] ?? null) === 'complete' ? $profiles[$symbol] : null;
                $atOpen = $this->rules->exit($bar[1], $position + ['latched_exit_signal_id' => null], $profile, ['status' => 'pass'], $p);
                if ($atOpen['status'] === 'signal') {
                    $sell($symbol, $position, $bar[1], $atOpen['reason'], $date, $i);
                    unset($positions[$symbol]);
                }
            }
            if (in_array('market_filter_off', $profiles[array_key_first($profiles)]['entry_blockers'] ?? [], true)) {
                $counts['market_filter_off_days']++;
            }
            // Opening entries precede intraday crossings. Daily bars cannot order intraday events:
            // retain all positions and their cash usage until every entry has been considered.
            foreach ([true, false] as $opening) {
                foreach ($bars as $symbol => $series) {
                    $profile = $profiles[$symbol];
                    $bar = isset($index[$symbol][$date]) ? $series[$index[$symbol][$date]] : null;
                    if (($profile['status'] ?? null) !== 'complete' || ! in_array($profile['setup'], ['breakout', 'pullback'], true) || $bar === null) {
                        continue;
                    }
                    $trigger = Decimal::from($profile['levels']['trigger']);
                    if ((Decimal::from($bar[1])->compareTo($trigger) > 0) !== $opening) {
                        continue;
                    }
                    $counts['setups']++;
                    if (isset($positions[$symbol]) || SignalRules::marketFilterBlocks($profile, $p)
                        || (isset($lastExit[$symbol]) && $i - $lastExit[$symbol] < $p['cooldown_sessions'])) {
                        continue;
                    }
                    if (count($positions) >= $settings['max_open_positions']) {
                        $counts['skipped_capacity']++;

                        continue;
                    }
                    // Approximates the live 24-hour quote volume rule with the prior day's base volume at its close.
                    $prior = $instruments === null ? null : $series[$index[$symbol][$prev] ?? -1] ?? null;
                    if ($instruments !== null && ($prior === null || Decimal::from($prior[5])->multipliedBy(Decimal::from($prior[4]))->compareTo(Decimal::from($settings['min_daily_quote_volume'])) < 0)) {
                        $counts['skipped_liquidity']++;

                        continue;
                    }
                    // The first tradable price above the trigger: the open after a gap, otherwise one tick (a cent for equities) through it.
                    $tick = Decimal::from($instruments === null ? '0.01' : $instruments[$symbol]['price_increment']);
                    $price = Decimal::from($bar[1])->compareTo($trigger) > 0 ? Decimal::from($bar[1])
                        : (Decimal::from($bar[2])->compareTo($trigger) > 0 ? ($instruments === null ? $trigger->plus($tick) : InstrumentSizing::down($trigger, (string) $tick)->plus($tick)) : null);
                    $signal = $price === null ? null : $this->rules->entry($profile, (string) $price, $p);
                    if (($signal['status'] ?? null) !== 'signal') {
                        $counts['skipped_chase_or_no_cross']++;

                        continue;
                    }
                    $fill = $price->multipliedBy(Decimal::from('1')->plus($this->bps($settings['slippage_bps'])));
                    $fill = $instruments === null ? $fill->rounded(4, RoundingMode::UP) : InstrumentSizing::up($fill, $instruments[$symbol]['price_increment']);
                    $stop = $fill->minus(Decimal::from($profile['indicators']['atr'])->multipliedBy(Decimal::from($p['initial_stop_atr'])));
                    // Reflect opening gaps, realized opening losses and fees from preceding entries.
                    // Intraday marks are unknown; retain opening marks and each new position's fill.
                    $size = $instruments === null ? $this->sizer->calculate($this->mark($cash, $positions, $marks), $cash, $fill, $stop, $settings, null, $scale)
                        : $this->sizer->calculate($this->mark($cash, $positions, $marks), $cash, $fill, $stop, $settings, instrument: $instruments[$symbol] + ['asset_class' => 'crypto']);
                    $quantity = Decimal::from($size['quantity']);
                    if ($quantity->compareTo($zero) <= 0) {
                        $counts['skipped_zero_size']++;

                        continue;
                    }
                    $fee = $this->costs->fee($settings['cost_schedule'], 'BUY', $quantity, $fill);
                    $cash = $cash->minus($quantity->multipliedBy($fill))->minus($fee);
                    $positions[$symbol] = ['quantity' => (string) $quantity, 'entry_price' => (string) $fill, 'entry_atr' => $profile['indicators']['atr'],
                        'initial_stop' => (string) $stop, 'trailing_stop' => (string) $stop, 'sessions_held' => 0, 'progressed' => false,
                        'entry_date' => $date, 'setup' => $profile['setup'], 'buy_fee' => (string) $fee];
                    $marks[$symbol] = (string) $fill;
                    $counts['entries']++;
                }
            }
            foreach ($positions as $symbol => $position) {
                if (! isset($index[$symbol][$date])) {
                    continue;
                }
                $bar = $bars[$symbol][$index[$symbol][$date]];
                $stop = Decimal::from($position['initial_stop'])->compareTo(Decimal::from($position['trailing_stop'])) >= 0
                    ? Decimal::from($position['initial_stop']) : Decimal::from($position['trailing_stop']);
                // The entry-day low may precede the entry; retain the adverse stop-out assumption.
                if (Decimal::from($bar[3])->compareTo($stop) <= 0) {
                    $sell($symbol, $position, (string) $stop, $position['entry_date'] === $date ? 'entry_day_stop_assumed' : 'intraday_stop_hit', $date, $i);
                    unset($positions[$symbol]);
                }
            }
            // After the close: the live trailing, holding and progress rules.
            foreach ($positions as $symbol => &$position) {
                if (! isset($index[$symbol][$date])) {
                    continue;
                }
                $close = Decimal::from($bars[$symbol][$index[$symbol][$date]][4]);
                $atr = Decimal::from($position['entry_atr']);
                $candidate = $close->minus($atr->multipliedBy(Decimal::from($p['trailing_stop_atr'])));
                if ($candidate->compareTo(Decimal::from($position['trailing_stop'])) > 0) {
                    $position['trailing_stop'] = (string) $candidate;
                }
                $position['sessions_held']++;
                $position['progressed'] = $position['progressed'] || $close->compareTo(Decimal::from($position['entry_price'])->plus($atr)) >= 0;
            }
            unset($position);
            foreach ($bars as $symbol => $series) {
                if (isset($index[$symbol][$date])) {
                    $lastClose[$symbol] = $series[$index[$symbol][$date]][4];
                }
            }
            $value = $this->mark($cash, $positions, $lastClose);
            $exposed += $positions === [] ? 0 : 1;
            $peak = $value->compareTo($peak) > 0 ? $value : $peak;
            $fall = $peak->minus($value)->multipliedBy(Decimal::from('100'))->dividedBy($peak, 4);
            $drawdown = $fall->compareTo($drawdown) > 0 ? $fall : $drawdown;
            $curve[] = [$date, (string) $value->rounded(2, RoundingMode::HALF_EVEN)];
        }

        return ['version' => self::VERSION, 'start' => $curve[0][0] ?? null, 'end' => $curve[array_key_last($curve)][0] ?? null, 'sessions' => count($curve),
            'initial_equity' => $equity, 'final_equity' => $curve[array_key_last($curve)][1] ?? $equity,
            'stats' => $this->stats($trades, $curve, $equity, $drawdown, $exposed), 'counts' => $counts,
            'open_positions' => array_map(fn ($p) => array_intersect_key($p, array_flip(['quantity', 'entry_price', 'entry_date', 'setup'])), $positions),
            'benchmarks' => $this->benchmarks($bars, $benchmark, $curve[0][0] ?? null, $curve[array_key_last($curve)][0] ?? null),
            'trades' => $trades, 'equity_curve' => $curve,
            'execution' => ['order_mode' => $mode, 'quantity_scale' => $scale, 'slippage_bps' => $settings['slippage_bps']],
            'risk' => ['max_open_positions' => $settings['max_open_positions'], 'max_position_pct' => $settings['max_position_pct'], 'risk_per_trade_pct' => $settings['risk_per_trade_pct']],
            'limitations' => $instruments !== null ? ['daily_bars_approximate_five_minute_triggers_and_stops', 'current_collection_list_applied_to_past_selection_bias',
                'current_venue_increments_and_fee_tier_applied_to_past', 'prior_day_base_volume_times_close_approximates_24h_quote_volume',
                'no_spread_quote_age_or_book_liquidity', 'daily_loss_pause_not_modeled', 'intraday_exit_cash_and_capacity_unavailable_until_next_day',
                'intraday_sizing_uses_opening_marks_and_new_entry_fills']
                : ['daily_bars_approximate_five_minute_triggers_and_stops', 'earnings_blackout_not_reconstructed', 'opening_delay_not_modeled',
                    'current_watchlist_applied_to_past_selection_bias', 'no_intraday_quotes_or_book_liquidity',
                    ...($scale === 0 ? ['whole_share_limit_orders_per_reviewed_broker_contract']
                        : ['fractional_market_orders_regular_hours_only', 'market_order_slippage_assumed_not_measured', 'symbol_fractional_eligibility_assumed']),
                    'intraday_exit_cash_and_capacity_unavailable_until_next_session', 'intraday_sizing_uses_opening_marks_and_new_entry_fills']];
    }

    private function mark(Decimal $cash, array $positions, array $closes): Decimal
    {
        foreach ($positions as $symbol => $position) {
            $cash = $cash->plus(Decimal::from($position['quantity'])->multipliedBy(Decimal::from($closes[$symbol] ?? $position['entry_price'])));
        }

        return $cash;
    }

    private function bps(string $bps): Decimal
    {
        return Decimal::from($bps)->dividedBy(Decimal::from('10000'), 18);
    }

    private function stats(array $trades, array $curve, string $equity, Decimal $drawdown, int $exposed): array
    {
        $wins = array_filter($trades, fn ($t) => Decimal::from($t['net'])->compareTo(Decimal::from('0')) > 0);
        $losses = array_filter($trades, fn ($t) => Decimal::from($t['net'])->compareTo(Decimal::from('0')) <= 0);
        $sum = fn (array $rows, string $key) => array_reduce($rows, fn (Decimal $c, $t) => $c->plus(Decimal::from($t[$key])), Decimal::from('0'));
        $avg = fn (array $rows) => $rows === [] ? null : (string) $sum($rows, 'return_pct')->dividedBy(Decimal::from((string) count($rows)), 4);
        $lossTotal = $sum($losses, 'net');
        $final = Decimal::from($curve[array_key_last($curve)][1] ?? $equity);

        return ['closed_trades' => count($trades), 'wins' => count($wins), 'losses' => count($losses),
            'hit_rate_pct' => $trades === [] ? null : (string) Decimal::from((string) count($wins))->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from((string) count($trades)), 2),
            'average_win_pct' => $avg($wins), 'average_loss_pct' => $avg($losses),
            'profit_factor' => $lossTotal->compareTo(Decimal::from('0')) === 0 ? null : (string) $sum($wins, 'net')->dividedBy(Decimal::from('0')->minus($lossTotal), 4),
            'total_return_pct' => (string) $final->minus(Decimal::from($equity))->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from($equity), 4),
            'max_drawdown_pct' => (string) $drawdown, 'exposure_pct' => $curve === [] ? null : (string) Decimal::from((string) $exposed)->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from((string) count($curve)), 2)];
    }

    /** Buy-and-hold from the first replay open to the last close, for SPY and an equal-weight average of the universe. */
    private function benchmarks(array $bars, array $benchmark, ?string $start, ?string $end): array
    {
        $change = function (array $series) use ($start, $end): ?Decimal {
            $in = array_values(array_filter($series, fn ($b) => $b[0] >= $start && $b[0] <= $end));

            return $in === [] ? null : Decimal::from($in[array_key_last($in)][4])->dividedBy(Decimal::from($in[0][1]), 8)->minus(Decimal::from('1'))->multipliedBy(Decimal::from('100'));
        };
        $each = array_filter(array_map($change, $bars));
        $sum = array_reduce($each, fn (Decimal $c, Decimal $v) => $c->plus($v), Decimal::from('0'));

        return ['benchmark_buy_and_hold_pct' => ($spy = $change($benchmark)) ? (string) $spy->rounded(4, RoundingMode::HALF_EVEN) : null,
            'equal_weight_universe_pct' => $each === [] ? null : (string) $sum->dividedBy(Decimal::from((string) count($each)), 4)];
    }
}
