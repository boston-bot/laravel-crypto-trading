<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Services\MarketData\Alpaca\HistoricalQuotes;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/** Quote sampling mode of StrategyReplay; shares the same rule, sizing, cost and fill-price implementations. */
trait IntradayQuoteReplay
{
    public function runQuotes(array $data, array $p, array $settings, string $initial): array
    {
        return $this->runQuoteSession($data, $p, $settings, $initial)['result'];
    }

    /** Internal session step; only runQuoteSessions supplies prior state after validating the whole window. */
    private function runQuoteSession(array $data, array $p, array $settings, string $initial, ?array $state = null, ?int $sessionIndex = null): array
    {
        if (! ($data['complete'] ?? false)) {
            throw new \InvalidArgumentException('Incomplete historical acquisition.');
        }
        $open = CarbonImmutable::parse($data['session']['opens_at']);
        $end = CarbonImmutable::parse($data['window_end']);
        if (! TradingCosts::valid($settings['cost_schedule'], $open) || Decimal::from($initial)->compareTo(Decimal::from('0')) <= 0 ||
            $settings['allow_fractional'] !== false || $settings['max_open_positions'] > 8 || $settings['max_open_positions'] < 1 ||
            Decimal::from($settings['max_position_pct'])->compareTo(Decimal::from('20')) > 0 || Decimal::from($settings['risk_per_trade_pct'])->compareTo(Decimal::from('1')) > 0) {
            throw new \InvalidArgumentException('Unsupported historical research settings.');
        }
        $symbols = $data['symbols'];
        sort($symbols);
        $daily = [];
        foreach ($data['daily_reads'] as $symbol => $read) {
            if (($read['adjustment'] ?? null) !== 'raw') {
                throw new \InvalidArgumentException('Historical quotes require raw profile history.');
            }
            // Even a directly supplied research snapshot cannot let this session's bars enter its profile.
            $daily[$symbol] = array_values(array_filter($read['bars'], fn ($b) => $b[0] < $data['date']));
        }
        $profiles = [];
        $facts = [];
        foreach ($symbols as $symbol) {
            $bars = $daily[$symbol] ?? [];
            $profiles[$symbol] = $bars !== [] && $bars[array_key_last($bars)][0] === $data['previous_session']
                ? $this->profiles->compute($bars, $daily[$p['benchmark_symbol']] ?? [], $p, ['status' => 'pass', 'reason' => 'research_assumption_not_historical_earnings_evidence']) : null;
            $facts[$symbol] = $bars === [] ? null : ['verified' => true] + PaperMarketReferences::facts($bars);
        }
        $zero = Decimal::from('0');
        $cash = Decimal::from($state['cash'] ?? $initial);
        $positions = $state['positions'] ?? [];
        $orders = $state['orders'] ?? [];
        $fills = $state['fills'] ?? [];
        $trades = $state['trades'] ?? [];
        $curve = $state['curve'] ?? [];
        $events = $state['events'] ?? [];
        $pending = [];
        $exited = $state['exited'] ?? [];
        $last = null;
        $seq = $state['seq'] ?? 0;
        $drawdown = Decimal::from($state['drawdown'] ?? '0');
        $peak = Decimal::from($state['peak'] ?? $initial);
        $exposed = $state['exposed'] ?? 0;
        $known = $state['known'] ?? 0;
        $total = $state['total'] ?? 0;
        $openingEquity = $state === null ? $initial : null;
        $lossPaused = false;
        if ($state !== null) {
            // Only the preceding completed session changes held-position state; never today's close.
            foreach ($positions as $symbol => &$position) {
                $series = $daily[$symbol] ?? [];
                $bar = $series === [] ? null : $series[array_key_last($series)];
                if ($bar === null || $bar[0] !== $data['previous_session']) {
                    throw new \InvalidArgumentException('Held position has no preceding completed close.');
                }
                $close = Decimal::from($bar[4]);
                $atr = Decimal::from($position['entry_atr']);
                $candidate = $close->minus($atr->multipliedBy(Decimal::from($p['trailing_stop_atr'])));
                if ($candidate->compareTo(Decimal::from($position['trailing_stop'])) > 0) {
                    $position['trailing_stop'] = (string) $candidate;
                }
                $position['sessions_held']++;
                $position['progressed'] = $position['progressed'] || $close->compareTo(Decimal::from($position['entry_price'])->plus($atr)) >= 0;
            }
            unset($position);
        }
        foreach ($data['frames'] as $frame) {
            $at = CarbonImmutable::parse($frame['at']);
            if ($at->lt($open) || $at->gte($end) || ($last !== null && $at->lte($last))) {
                throw new \InvalidArgumentException('Unordered or out-of-session replay frame.');
            }
            $last = $at;
            $quotes = [];
            foreach ($symbols as $symbol) {
                $total++;
                $q = $frame['quotes'][$symbol] ?? null;
                $quotes[$symbol] = $this->intradayQuote($q, $at, $open, $settings['quote_max_age_seconds']);
                if ($quotes[$symbol] !== null) {
                    $known++;
                }
            }
            if ($sessionIndex !== null) {
                if ($last->eq($open) && $openingEquity === null) {
                    $opening = $this->intradayEquity($cash, $positions, $quotes);
                    $openingEquity = $opening === null ? null : (string) $opening;
                }
                $currentValue = $this->intradayEquity($cash, $positions, $quotes);
                $lossPaused = $lossPaused || $openingEquity === null || Decimal::from($openingEquity)->compareTo($zero) <= 0 ||
                    ($currentValue !== null && $this->intradayLossPaused($currentValue, $openingEquity, $settings));
            }
            // Previously accepted orders may fill before the new cycle decides. A later intraday sale never
            // funds an earlier decision. Each quote watermark is consumed once per immutable intent.
            foreach ($frame['kind'] === 'mark' ? [] : $pending as $symbol => $id) {
                $q = $quotes[$symbol];
                $order = &$orders[$id];
                if ($q === null || $q['timestamp_ns'] <= HistoricalQuotes::timestamp($order['accepted_at']) || $q['timestamp_ns'] <= ($order['last_quote_ns'] ?? '')) {
                    unset($order);

                    continue;
                }
                $order['last_quote_ns'] = $q['timestamp_ns'];
                $side = $order['side'];
                $buy = $side === 'BUY';
                $price = PaperFillPrice::calculate($side, $q[$buy ? 'ask' : 'bid'], $settings['slippage_bps']);
                $remaining = Decimal::from($order['quantity'])->minus(Decimal::from($order['filled_quantity']));
                $size = Decimal::from($q[$buy ? 'ask_size' : 'bid_size']);
                $quantity = ($remaining->compareTo($size) < 0 ? $remaining : $size)->rounded(0, RoundingMode::DOWN);
                $marketable = $buy ? $price->compareTo(Decimal::from($order['limit'])) <= 0 : $price->compareTo(Decimal::from($order['limit'])) >= 0;
                if (! $marketable || $quantity->compareTo($zero) <= 0) {
                    unset($order);

                    continue;
                }
                if ($buy) {
                    $value = $this->intradayEquity($cash, $positions, $quotes);
                    [$available, $capacity] = $this->intradayCapacity($cash, $value, $positions, $quotes, $orders, $settings, $id);
                    $stop = Decimal::from($order['limit'])->minus(Decimal::from($order['atr'])->multipliedBy(Decimal::from($p['initial_stop_atr'])));
                    $sized = $value ? $this->sizer->calculate($value, $available, Decimal::from($order['limit']), $stop, $settings, $capacity) : ['quantity' => '0'];
                    $check = app(EligibilityRules::class)->thresholds($q, $facts[$symbol], RuleEligibilityPolicies::DEFAULTS);
                    $signal = $this->rules->entry($profiles[$symbol], $q['ask'], $p);
                    if ($value === null || ($sessionIndex === null ? $this->intradayLossPaused($value, $initial, $settings) : $lossPaused) || $signal['status'] !== 'signal' ||
                        array_filter($check, fn ($c) => $c['status'] !== 'pass') || $remaining->compareTo(Decimal::from($sized['quantity'])) > 0 ||
                        Decimal::from($order['quantity'])->multipliedBy(Decimal::from($order['limit']))->multipliedBy(Decimal::from('100'))->compareTo($value->multipliedBy(Decimal::from($settings['max_position_pct']))) > 0) {
                        unset($order);

                        continue;
                    }
                }
                $fee = $this->costs->fee($settings['cost_schedule'], $side, $quantity, $price);
                $notional = $quantity->multipliedBy($price);
                if ($buy && $notional->plus($fee)->compareTo($cash) > 0) {
                    unset($order);

                    continue;
                }
                if ($buy) {
                    $cash = $cash->minus($notional)->minus($fee);
                    $pos = $positions[$symbol] ?? ['quantity' => '0', 'entry_price' => '0', 'buy_cost' => '0', 'sold_net' => '0', 'entry_quantity' => '0', 'entry_date' => $data['date'],
                        'entry_atr' => $order['atr'], 'setup' => $profiles[$symbol]['setup'], 'latched_exit_signal_id' => null, 'sessions_held' => 0, 'progressed' => false];
                    $old = Decimal::from($pos['quantity']);
                    $new = $old->plus($quantity);
                    $pos['entry_price'] = (string) Decimal::from($pos['entry_price'])->multipliedBy($old)->plus($notional)->dividedBy($new, 18);
                    $pos['quantity'] = (string) $new;
                    $pos['entry_quantity'] = (string) Decimal::from($pos['entry_quantity'])->plus($quantity);
                    $pos['buy_cost'] = (string) Decimal::from($pos['buy_cost'])->plus($notional)->plus($fee);
                    $pos['initial_stop'] = $pos['trailing_stop'] = (string) Decimal::from($pos['entry_price'])->minus(Decimal::from($pos['entry_atr'])->multipliedBy(Decimal::from($p['initial_stop_atr'])));
                    $positions[$symbol] = $pos;
                } else {
                    $cash = $cash->plus($notional)->minus($fee);
                    $positions[$symbol]['sold_net'] = (string) Decimal::from($positions[$symbol]['sold_net'])->plus($notional)->minus($fee);
                    $positions[$symbol]['quantity'] = (string) Decimal::from($positions[$symbol]['quantity'])->minus($quantity);
                    if (Decimal::from($positions[$symbol]['quantity'])->compareTo($zero) === 0) {
                        $pos = $positions[$symbol];
                        $net = Decimal::from($pos['sold_net'])->minus(Decimal::from($pos['buy_cost']));
                        $trades[] = ['symbol' => $symbol, 'setup' => $pos['setup'], 'quantity' => $pos['entry_quantity'], 'entry_date' => $pos['entry_date'], 'exit_date' => $data['date'],
                            'reason' => $order['reason'], 'net' => (string) $net->rounded(2, RoundingMode::HALF_EVEN),
                            'return_pct' => (string) $net->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from($pos['buy_cost']), 4)];
                        if ($sessionIndex !== null) {
                            $trades[array_key_last($trades)]['net_exact'] = (string) $net;
                        }
                        unset($positions[$symbol]);
                        $exited[$symbol] = $sessionIndex ?? 0;
                    }
                }
                $order['filled_quantity'] = (string) Decimal::from($order['filled_quantity'])->plus($quantity);
                $order['status'] = Decimal::from($order['quantity'])->compareTo(Decimal::from($order['filled_quantity'])) === 0 ? 'filled' : 'partial';
                $order['reserved_cash'] = $buy && $order['status'] !== 'filled' ? (string) $this->intradayReserve(Decimal::from($order['quantity'])->minus(Decimal::from($order['filled_quantity'])), $order['limit'], $settings) : '0';
                $fills[] = ['order_id' => $id, 'symbol' => $symbol, 'side' => $side, 'at' => $at->toISOString(), 'source_ns' => $q['timestamp_ns'], 'quantity' => (string) $quantity, 'price' => (string) $price, 'fee' => (string) $fee];
                if ($order['status'] === 'filled') {
                    unset($pending[$symbol]);
                }
                unset($order);
                if ($sessionIndex !== null && ! $lossPaused) {
                    $afterFill = $this->intradayEquity($cash, $positions, $quotes);
                    $lossPaused = $afterFill !== null && $this->intradayLossPaused($afterFill, $openingEquity, $settings);
                }
            }
            if ($frame['kind'] === 'decision') {
                foreach ($symbols as $symbol) {
                    $q = $quotes[$symbol];
                    $profile = $profiles[$symbol];
                    $reason = null;
                    $side = null;
                    if ($q === null) {
                        $reason = 'historical_quote_unavailable';
                    } elseif (isset($positions[$symbol])) {
                        $signal = $this->rules->exit($q['bid'], $positions[$symbol], ($profile['status'] ?? null) === 'complete' ? $profile : null, ['status' => 'pass'], $p);
                        $reason = $signal['reason'];
                        $side = $signal['side'];
                    } elseif ($at->lt($open->addMinutes($p['entry_delay_minutes']))) {
                        $reason = 'opening_entry_delay';
                    } elseif (isset($exited[$symbol]) && ($sessionIndex === null || $sessionIndex - $exited[$symbol] < $p['cooldown_sessions'])) {
                        $reason = 'reentry_cooldown';
                    } elseif (($profile['status'] ?? null) !== 'complete') {
                        $reason = 'profile_unavailable';
                    } elseif (SignalRules::marketFilterBlocks($profile, $p)) {
                        $reason = 'market_filter_off';
                    } else {
                        $checks = app(EligibilityRules::class)->thresholds($q, $facts[$symbol], RuleEligibilityPolicies::DEFAULTS);
                        if (array_filter($checks, fn ($c) => $c['status'] !== 'pass')) {
                            $reason = 'eligibility_threshold_failed';
                        } else {
                            $signal = $this->rules->entry($profile, $q['ask'], $p);
                            $reason = $signal['reason'];
                            $side = $signal['side'];
                        }
                    }
                    if ($side !== null) {
                        if (isset($pending[$symbol])) {
                            $old = $pending[$symbol];
                            if ($side === 'SELL' && $orders[$old]['side'] === 'SELL') {
                                $orders[$old]['status'] = 'cancelled_for_reprice';
                                $orders[$old]['reserved_cash'] = '0';
                                unset($pending[$symbol]);
                            } else {
                                $side = null;
                                $reason = 'open_order_exists';
                            }
                        }
                    }
                    if ($side !== null) {
                        $buy = $side === 'BUY';
                        $reference = Decimal::from($q[$buy ? 'ask' : 'bid']);
                        $bound = $reference->multipliedBy(Decimal::from('1')->plus($this->bps($settings['limit_offset_bps'])->multipliedBy(Decimal::from($buy ? '1' : '-1'))))->rounded(4, $buy ? RoundingMode::DOWN : RoundingMode::UP);
                        $quantity = $positions[$symbol]['quantity'] ?? '0';
                        if ($buy) {
                            $max = Decimal::from($profile['levels']['max_entry']);
                            if ($bound->compareTo($max) > 0) {
                                $bound = $max;
                            }
                            $value = $this->intradayEquity($cash, $positions, $quotes);
                            $occupied = array_unique([...array_keys($positions), ...array_keys($pending)]);
                            if ($value === null || ($sessionIndex === null ? $this->intradayLossPaused($value, $initial, $settings) : $lossPaused) || count($occupied) >= $settings['max_open_positions']) {
                                $quantity = '0';
                                $reason = 'equity_loss_or_capacity_unavailable';
                            } else {
                                [$available, $cap] = $this->intradayCapacity($cash, $value, $positions, $quotes, $orders, $settings);
                                $stop = $bound->minus(Decimal::from($profile['indicators']['atr'])->multipliedBy(Decimal::from($p['initial_stop_atr'])));
                                $sizing = $this->sizer->calculate($value, $available, $bound, $stop, $settings, $cap);
                                $quantity = $sizing['quantity'];
                                if (Decimal::from($quantity)->compareTo($zero) === 0) {
                                    $reason = 'whole_share_size_zero';
                                }
                            }
                        }
                        if (Decimal::from($quantity)->compareTo($zero) > 0) {
                            $id = ++$seq;
                            $orders[$id] = ['id' => $id, 'symbol' => $symbol, 'side' => $side, 'quantity' => $quantity, 'filled_quantity' => '0', 'limit' => (string) $bound,
                                'accepted_at' => $at->toISOString(), 'atr' => $buy ? $profile['indicators']['atr'] : $positions[$symbol]['entry_atr'], 'reason' => $reason, 'status' => 'open',
                                'reserved_cash' => $buy ? (string) $this->intradayReserve(Decimal::from($quantity), (string) $bound, $settings) : '0'];
                            $pending[$symbol] = $id;
                            if (! $buy) {
                                $positions[$symbol]['latched_exit_signal_id'] ??= $id;
                            }
                        }
                    }
                    $events[] = ['at' => $at->toISOString(), 'symbol' => $symbol, 'reason' => $reason, 'side' => $side];
                }
            }
            $value = $this->intradayEquity($cash, $positions, $quotes);
            if ($value !== null) {
                if ($value->compareTo($peak) > 0) {
                    $peak = $value;
                }
                $dd = $peak->minus($value)->multipliedBy(Decimal::from('100'))->dividedBy($peak, 4);
                if ($dd->compareTo($drawdown) > 0) {
                    $drawdown = $dd;
                }
            }
            $curve[] = [$at->toISOString(), $value === null ? null : (string) $value->rounded(18, RoundingMode::HALF_EVEN)];
            if ($positions !== []) {
                $exposed++;
            }
        }
        if ($end->eq(CarbonImmutable::parse($data['session']['closes_at']))) {
            foreach ($orders as &$order) {
                if (in_array($order['status'], ['open', 'partial'], true)) {
                    $order['status'] = 'expired';
                    $order['reserved_cash'] = '0';
                }
            }
            unset($order);
        }
        $final = $curve === [] ? null : $curve[array_key_last($curve)][1];
        $statTrades = $sessionIndex === null ? $trades : array_map(fn ($trade) => array_replace($trade, ['net' => $trade['net_exact']]), $trades);
        $stats = $this->stats($statTrades, array_values(array_filter($curve, fn ($row) => $row[1] !== null)), $initial, $drawdown, $exposed);
        $stats['exposure_pct'] = $curve === [] ? null : (string) Decimal::from((string) $exposed)->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from((string) count($curve)), 2);
        if ($final === null) {
            $stats['total_return_pct'] = null;
        }

        $result = ['version' => self::INTRADAY_VERSION, 'label' => 'retrospective_sampled_quote_scenario_not_forward_or_point_in_time', 'final_equity' => $final,
            'cash' => (string) $cash, 'profiles' => $profiles, 'orders' => array_values($orders), 'fills' => $fills, 'trades' => $trades, 'open_positions' => $positions,
            'curve' => $curve, 'events' => $events, 'stats' => $stats, 'coverage' => ['known_quotes' => $known, 'expected_quotes' => $total],
            'limitations' => ['fixed_research_universe_not_historical_watchlist', 'historical_earnings_blackout_assumed_clear', 'historical_listing_classification_and_broker_permission_assumed',
                'sampled_quotes_not_continuous_order_book_or_queue_priority', 'event_time_not_historical_arrival_latency', 'single_session_flat_start_no_overnight_actions',
                'missing_marks_leave_equity_unknown', 'drawdown_measured_only_at_known_quote_samples', 'unfilled_day_orders_remain_pending_at_sample_window_end', 'retrospective_provider_corrections_possible']];

        return ['result' => $result, 'opening_equity' => $openingEquity, 'daily_loss_paused' => $lossPaused,
            'state' => compact('positions', 'orders', 'fills', 'trades', 'curve', 'events', 'exited', 'seq', 'exposed', 'known', 'total') +
                ['cash' => (string) $cash, 'drawdown' => (string) $drawdown, 'peak' => (string) $peak]];
    }

    private function intradayQuote(?array $q, CarbonImmutable $at, CarbonImmutable $open, int $age): ?array
    {
        if ($q === null || ($q['conditions'] ?? null) !== ['R'] || ($q['size_unit'] ?? null) !== 'shares' || ($q['feed'] ?? null) !== 'sip') {
            return null;
        }
        try {
            $time = HistoricalQuotes::timestamp($q['timestamp_ns']);
            if ($time > HistoricalQuotes::timestamp($at->toISOString()) || $time < HistoricalQuotes::timestamp($at->subSeconds($age)->max($open)->toISOString()) ||
                Decimal::from($q['bid'])->compareTo(Decimal::from('0')) <= 0 || Decimal::from($q['ask'])->compareTo(Decimal::from($q['bid'])) < 0) {
                return null;
            }

            return $q;
        } catch (\Throwable) {
            return null;
        }
    }

    private function intradayEquity(Decimal $cash, array $positions, array $quotes): ?Decimal
    {
        foreach ($positions as $symbol => $pos) {
            if (($quotes[$symbol] ?? null) === null) {
                return null;
            }
            $cash = $cash->plus(Decimal::from($pos['quantity'])->multipliedBy(Decimal::from($quotes[$symbol]['bid'])));
        }

        return $cash;
    }

    private function intradayReserve(Decimal $quantity, string $limit, array $settings): Decimal
    {
        return $quantity->multipliedBy(Decimal::from($limit))->plus($this->costs->buyReserve($settings['cost_schedule'], $quantity));
    }

    private function intradayLossPaused(Decimal $value, string $initial, array $settings): bool
    {
        return Decimal::from($initial)->minus($value)->multipliedBy(Decimal::from('100'))->compareTo(Decimal::from($initial)->multipliedBy(Decimal::from($settings['daily_loss_pause_pct']))) >= 0;
    }

    private function intradayCapacity(Decimal $cash, ?Decimal $value, array $positions, array $quotes, array $orders, array $settings, ?int $own = null): array
    {
        if ($value === null) {
            return [Decimal::from('0'), Decimal::from('0')];
        }
        $cash = $cash->minus($value->multipliedBy(Decimal::from($settings['cash_pct']))->dividedBy(Decimal::from('100'), 18));
        $cap = $value->multipliedBy(Decimal::from($settings['active_pct']))->dividedBy(Decimal::from('100'), 18);
        foreach ($positions as $symbol => $pos) {
            $cap = $cap->minus(Decimal::from($pos['quantity'])->multipliedBy(Decimal::from($quotes[$symbol]['bid'])));
        }
        foreach ($orders as $id => $order) {
            if ($id !== $own && $order['side'] === 'BUY' && in_array($order['status'], ['open', 'partial'], true)) {
                $cash = $cash->minus(Decimal::from($order['reserved_cash']));
                $cap = $cap->minus(Decimal::from($order['quantity'])->minus(Decimal::from($order['filled_quantity']))->multipliedBy(Decimal::from($order['limit'])));
            }
        }

        return [$cash, $cap];
    }
}
