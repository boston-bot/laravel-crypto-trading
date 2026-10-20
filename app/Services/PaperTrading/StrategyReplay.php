<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;

/**
 * Retrospective daily replay of the live profile, signal, sizing and cost rules over split-adjusted daily bars.
 * Each day uses only bars completed before it. It is a daily approximation of the five-minute loop, labeled as such:
 * no earnings blackout (dates are not reconstructed), no 15-minute opening delay, no intraday quotes or book
 * liquidity, and today's watchlist applied to the past (selection bias). Pure: no network, storage or model.
 */
final class StrategyReplay
{
    public const VERSION = 'daily_replay_v1';

    /** Bars per profile, matching the live 400-calendar-day history window (about 275 sessions). */
    public const LIVE_WINDOW = 275;

    public function __construct(private ProfileCalculator $profiles, private SignalRules $rules, private RiskSizer $sizer, private TradingCosts $costs) {}

    /**
     * @param  array<string, list<array>>  $bars  symbol => [date, o, h, l, c, v, vw, n] oldest first
     * @param  list<array>  $benchmark  benchmark bars; its dates are the replay's trading sessions
     */
    public function run(array $bars, array $benchmark, array $p, array $settings, string $equity): array
    {
        ksort($bars);
        $dates = array_column($benchmark, 0);
        $index = [];
        foreach ($bars as $symbol => $series) {
            $index[$symbol] = array_flip(array_column($series, 0));
        }
        $first = max($p['market_filter_sma_sessions'], $p['slow_sma_sessions']);
        $zero = Decimal::from('0');
        $cash = Decimal::from($equity);
        $positions = [];
        $lastExit = [];
        $lastClose = [];
        $trades = [];
        $curve = [];
        $counts = ['setups' => 0, 'entries' => 0, 'skipped_zero_size' => 0, 'skipped_capacity' => 0, 'skipped_chase_or_no_cross' => 0, 'market_filter_off_days' => 0];
        $peak = Decimal::from($equity);
        $drawdown = $zero;
        $exposed = 0;
        $sell = function (string $symbol, array $position, string $price, string $reason, string $date, int $i) use (&$cash, &$trades, &$lastExit, $settings): void {
            $fill = Decimal::from($price)->multipliedBy(Decimal::from('1')->minus($this->bps($settings['slippage_bps'])))->rounded(4, RoundingMode::DOWN);
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
            $bench = array_slice($benchmark, max(0, $i - self::LIVE_WINDOW), min($i, self::LIVE_WINDOW));
            $profiles = [];
            foreach ($bars as $symbol => $series) {
                $at = $index[$symbol][$prev] ?? null;
                $profiles[$symbol] = $at === null ? null
                    : $this->profiles->compute(array_slice($series, max(0, $at - self::LIVE_WINDOW + 1), min($at + 1, self::LIVE_WINDOW)), $bench, $p, ['status' => 'pass', 'reason' => 'not_reconstructed_in_replay']);
            }
            $equityNow = $this->mark($cash, $positions, $lastClose);
            // Exits first, at the open, then intraday stops.
            foreach ($positions as $symbol => $position) {
                $bar = isset($index[$symbol][$date]) ? $bars[$symbol][$index[$symbol][$date]] : null;
                if ($bar === null) {
                    continue;
                }
                $profile = ($profiles[$symbol]['status'] ?? null) === 'complete' ? $profiles[$symbol] : null;
                $atOpen = $this->rules->exit($bar[1], $position + ['latched_exit_signal_id' => null], $profile, ['status' => 'pass'], $p);
                $stop = Decimal::from($position['initial_stop'])->compareTo(Decimal::from($position['trailing_stop'])) >= 0 ? $position['initial_stop'] : $position['trailing_stop'];
                [$price, $reason] = $atOpen['status'] === 'signal' ? [$bar[1], $atOpen['reason']]
                    : (Decimal::from($bar[3])->compareTo(Decimal::from($stop)) <= 0 ? [$stop, 'intraday_stop_hit'] : [null, null]);
                if ($price !== null) {
                    $sell($symbol, $position, $price, $reason, $date, $i);
                    unset($positions[$symbol]);
                }
            }
            if (in_array('market_filter_off', $profiles[array_key_first($profiles)]['entry_blockers'] ?? [], true)) {
                $counts['market_filter_off_days']++;
            }
            foreach ($bars as $symbol => $series) {
                $profile = $profiles[$symbol];
                $bar = isset($index[$symbol][$date]) ? $series[$index[$symbol][$date]] : null;
                if (($profile['status'] ?? null) !== 'complete' || ! in_array($profile['setup'], ['breakout', 'pullback'], true) || $bar === null) {
                    continue;
                }
                $counts['setups']++;
                if (isset($positions[$symbol]) || in_array('market_filter_off', $profile['entry_blockers'], true)
                    || (isset($lastExit[$symbol]) && $i - $lastExit[$symbol] < $p['cooldown_sessions'])) {
                    continue;
                }
                if (count($positions) >= $settings['max_open_positions']) {
                    $counts['skipped_capacity']++;

                    continue;
                }
                // The first tradable price above the trigger: the open after a gap, otherwise one cent through it.
                $trigger = Decimal::from($profile['levels']['trigger']);
                $price = Decimal::from($bar[1])->compareTo($trigger) > 0 ? Decimal::from($bar[1])
                    : (Decimal::from($bar[2])->compareTo($trigger) > 0 ? $trigger->plus(Decimal::from('0.01')) : null);
                $signal = $price === null ? null : $this->rules->entry($profile, (string) $price, $p);
                if (($signal['status'] ?? null) !== 'signal') {
                    $counts['skipped_chase_or_no_cross']++;

                    continue;
                }
                $fill = $price->multipliedBy(Decimal::from('1')->plus($this->bps($settings['slippage_bps'])))->rounded(4, RoundingMode::UP);
                $stop = $fill->minus(Decimal::from($profile['indicators']['atr'])->multipliedBy(Decimal::from($p['initial_stop_atr'])));
                $size = $this->sizer->calculate($equityNow, $cash, $fill, $stop, $settings);
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
                $counts['entries']++;
                // Daily bars cannot order the low against the entry; assume the worse case and stop out the same day.
                if (Decimal::from($bar[3])->compareTo($stop) <= 0) {
                    $sell($symbol, $positions[$symbol], (string) $stop, 'entry_day_stop_assumed', $date, $i);
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
            'limitations' => ['daily_bars_approximate_five_minute_triggers_and_stops', 'earnings_blackout_not_reconstructed', 'opening_delay_not_modeled',
                'current_watchlist_applied_to_past_selection_bias', 'no_intraday_quotes_or_book_liquidity', 'whole_share_limit_orders_per_reviewed_broker_contract']];
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
