<?php

declare(strict_types=1);

namespace App\Services\PaperTrading\Experiments;

use App\Services\MarketData\Alpaca\HistoricalQuotes;
use App\Services\PaperTrading\PaperFillPrice;
use App\Services\PaperTrading\TradingCosts;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Independent cash/inventory reconstruction from outputs; no engine state, network, storage or LLM. */
final class ReplayAccountingVerifier
{
    public const VERSION = 'quote_replay_accounting_v1';

    public static function usable(?array $quote, array $frame, array $session, int $age): bool
    {
        if ($quote === null || ($quote['conditions'] ?? null) !== ['R'] || ($quote['feed'] ?? null) !== 'sip' || ($quote['size_unit'] ?? null) !== 'shares') {
            return false;
        }
        $at = CarbonImmutable::parse($frame['at']);
        $open = CarbonImmutable::parse($session['opens_at']);

        return $quote['timestamp_ns'] <= HistoricalQuotes::timestamp($frame['at']) &&
            $quote['timestamp_ns'] >= HistoricalQuotes::timestamp($at->subSeconds($age)->max($open)->toISOString()) &&
            Decimal::from($quote['bid'])->compareTo(Decimal::from('0')) > 0 && Decimal::from($quote['ask'])->compareTo(Decimal::from($quote['bid'])) >= 0;
    }

    public function verify(array $sessions, array $result, array $settings, string $initial): array
    {
        $need = function (bool $condition): void {
            if (! $condition) {
                throw new RuntimeException('Replay accounting verification failed.');
            }
        };
        $eq = fn ($a, $b) => Decimal::from($a)->compareTo(Decimal::from($b)) === 0;
        $zero = Decimal::from('0');
        $canonical = fn (string $at) => HistoricalQuotes::timestamp($at);
        $frames = [];
        $known = 0;
        $expected = 0;
        foreach ($sessions as $data) {
            foreach ($data['daily_reads'] as $read) {
                $need($read['adjustment'] === 'raw');
                foreach ($read['bars'] as $bar) {
                    $need($bar[0] < $data['date']);
                }
            }
            foreach ($data['frames'] as $frame) {
                $need(! isset($frames[$canonical($frame['at'])]));
                $frames[$canonical($frame['at'])] = [$frame, $data['session']];
                foreach ($data['symbols'] as $symbol) {
                    $expected++;
                    $known += self::usable($frame['quotes'][$symbol] ?? null, $frame, $data['session'], $settings['quote_max_age_seconds']) ? 1 : 0;
                }
            }
        }
        $orders = [];
        foreach ($result['orders'] as $order) {
            $need(! isset($orders[$order['id']]) && isset($frames[$canonical($order['accepted_at'])]) && $frames[$canonical($order['accepted_at'])][0]['kind'] === 'decision');
            $need(in_array($order['status'], ['filled', 'expired', 'cancelled_for_reprice'], true) && $eq($order['reserved_cash'], '0'));
            $orders[$order['id']] = $order;
        }
        $fills = [];
        foreach ($result['fills'] as $fill) {
            $need(isset($frames[$canonical($fill['at'])], $orders[$fill['order_id']]));
            $fills[$canonical($fill['at'])][] = $fill;
        }
        $cash = Decimal::from($initial);
        $held = [];
        $watermarks = [];
        $filled = [];
        $episodes = [];
        $closed = [];
        $peak = $cash;
        $drawdown = $zero;
        $exposed = 0;
        $curve = [];
        $costs = app(TradingCosts::class);
        foreach ($frames as [$frame, $session]) {
            foreach ($fills[$canonical($frame['at'])] ?? [] as $fill) {
                $order = $orders[$fill['order_id']];
                $symbol = $fill['symbol'];
                $q = $frame['quotes'][$symbol] ?? null;
                $need($frame['kind'] !== 'mark' && self::usable($q, $frame, $session, $settings['quote_max_age_seconds']));
                $need($order['symbol'] === $symbol && $fill['side'] === $order['side'] && $q['timestamp_ns'] === $fill['source_ns']);
                $need($fill['source_ns'] > HistoricalQuotes::timestamp($order['accepted_at']) && $fill['source_ns'] > ($watermarks[$order['id']] ?? ''));
                $need(CarbonImmutable::parse($fill['at'])->setTimezone('America/New_York')->toDateString() === CarbonImmutable::parse($order['accepted_at'])->setTimezone('America/New_York')->toDateString());
                $watermarks[$order['id']] = $fill['source_ns'];
                $buy = $fill['side'] === 'BUY';
                $qty = Decimal::from($fill['quantity']);
                $price = Decimal::from($fill['price']);
                $fee = Decimal::from($fill['fee']);
                $need(preg_match('/^[1-9]\d*$/D', $fill['quantity']) === 1 && $qty->compareTo(Decimal::from($q[$buy ? 'ask_size' : 'bid_size'])) <= 0);
                $need($buy ? $price->compareTo(Decimal::from($order['limit'])) <= 0 : $price->compareTo(Decimal::from($order['limit'])) >= 0);
                $need($eq($fill['price'], (string) PaperFillPrice::calculate($fill['side'], $q[$buy ? 'ask' : 'bid'], $settings['slippage_bps'])));
                $need($eq($fill['fee'], (string) $costs->fee($settings['cost_schedule'], $fill['side'], $qty, $price)));
                $filled[$order['id']] = ($filled[$order['id']] ?? $zero)->plus($qty);
                $need($filled[$order['id']]->compareTo(Decimal::from($order['quantity'])) <= 0);
                $notional = $price->multipliedBy($qty);
                $cash = ($buy ? $cash->minus($notional) : $cash->plus($notional))->minus($fee);
                $held[$symbol] = $buy ? ($held[$symbol] ?? $zero)->plus($qty) : ($held[$symbol] ?? $zero)->minus($qty);
                $need($cash->compareTo($zero) >= 0 && $held[$symbol]->compareTo($zero) >= 0);
                $episodes[$symbol] ??= ['cost' => $zero, 'proceeds' => $zero, 'entry_date' => $session['date']];
                if ($buy) {
                    $episodes[$symbol]['cost'] = $episodes[$symbol]['cost']->plus($notional)->plus($fee);
                } else {
                    $episodes[$symbol]['proceeds'] = $episodes[$symbol]['proceeds']->plus($notional)->minus($fee);
                    if ($held[$symbol]->compareTo($zero) === 0) {
                        $closed[] = ['symbol' => $symbol, 'entry_date' => $episodes[$symbol]['entry_date'], 'exit_date' => $session['date'],
                            'net' => (string) $episodes[$symbol]['proceeds']->minus($episodes[$symbol]['cost'])];
                        unset($episodes[$symbol]);
                    }
                }
            }
            $value = $cash;
            $hasPosition = false;
            foreach ($held as $symbol => $qty) {
                if ($qty->compareTo($zero) === 0) {
                    continue;
                }
                $hasPosition = true;
                $q = $frame['quotes'][$symbol] ?? null;
                if (! self::usable($q, $frame, $session, $settings['quote_max_age_seconds'])) {
                    $value = null;
                    break;
                }
                $value = $value->plus($qty->multipliedBy(Decimal::from($q['bid'])));
            }
            $exposed += $hasPosition ? 1 : 0;
            if ($value !== null) {
                $peak = $value->compareTo($peak) > 0 ? $value : $peak;
                $dd = $peak->minus($value)->multipliedBy(Decimal::from('100'))->dividedBy($peak, 4);
                $drawdown = $dd->compareTo($drawdown) > 0 ? $dd : $drawdown;
            }
            $curve[] = [$frame['at'], $value === null ? null : (string) $value];
        }
        $need(count($curve) === count($result['curve']) && count($closed) === count($result['trades']));
        foreach ($curve as $index => $row) {
            $actual = $result['curve'][$index];
            $need($canonical($actual[0]) === $canonical($row[0]) && ($row[1] === null ? $actual[1] === null : $actual[1] !== null && $eq($row[1], $actual[1])));
        }
        $realized = $zero;
        foreach ($closed as $i => $trade) {
            $actual = $result['trades'][$i];
            $need($trade['symbol'] === $actual['symbol'] && $trade['entry_date'] === $actual['entry_date'] && $trade['exit_date'] === $actual['exit_date'] && $eq($trade['net'], $actual['net_exact']));
            $realized = $realized->plus(Decimal::from($trade['net']));
        }
        foreach (array_unique([...array_keys($held), ...array_keys($result['open_positions'])]) as $symbol) {
            $need($eq((string) ($held[$symbol] ?? $zero), $result['open_positions'][$symbol]['quantity'] ?? '0'));
            if (isset($result['open_positions'][$symbol])) {
                $need($eq((string) $episodes[$symbol]['cost'], $result['open_positions'][$symbol]['buy_cost']) && $eq((string) $episodes[$symbol]['proceeds'], $result['open_positions'][$symbol]['sold_net']));
            }
        }
        foreach ($orders as $id => $order) {
            $need($eq((string) ($filled[$id] ?? $zero), $order['filled_quantity']));
            $need(Decimal::from($order['quantity'])->compareTo($zero) > 0 &&
                ($order['status'] === 'filled') === $eq($order['filled_quantity'], $order['quantity']));
        }
        $wins = 0;
        $winNet = $lossNet = $zero;
        foreach ($closed as $trade) {
            $net = Decimal::from($trade['net']);
            if ($net->compareTo($zero) > 0) {
                $wins++;
                $winNet = $winNet->plus($net);
            } else {
                $lossNet = $lossNet->plus($net);
            }
        }
        $need($result['stats']['closed_trades'] === count($closed) && $result['stats']['wins'] === $wins && $result['stats']['losses'] === count($closed) - $wins);
        $need($closed === [] ? $result['stats']['hit_rate_pct'] === null : $eq((string) Decimal::from((string) $wins)->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from((string) count($closed)), 2), $result['stats']['hit_rate_pct']));
        $need($lossNet->compareTo($zero) === 0 ? $result['stats']['profit_factor'] === null : $eq((string) $winNet->dividedBy($zero->minus($lossNet), 4), $result['stats']['profit_factor']));
        $final = $curve[array_key_last($curve)][1];
        $need($eq((string) $cash, $result['cash']) && $eq((string) $realized, $result['closed_trade_net']));
        $need($final === null ? $result['final_equity'] === null && $result['stats']['total_return_pct'] === null && $result['open_episode_marked_pnl'] === null
            : $result['final_equity'] !== null && $eq($final, $result['final_equity']) &&
                $eq((string) Decimal::from($final)->minus(Decimal::from($initial))->minus($realized), $result['open_episode_marked_pnl']) &&
                $eq((string) Decimal::from($final)->minus(Decimal::from($initial))->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from($initial), 4), $result['stats']['total_return_pct']));
        $need($known === $result['coverage']['known_quotes'] && $expected === $result['coverage']['expected_quotes'] && $eq((string) $drawdown, $result['stats']['max_drawdown_pct']));
        $need($eq((string) Decimal::from((string) $exposed)->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from((string) count($curve)), 2), $result['stats']['exposure_pct']));

        return ['version' => self::VERSION, 'status' => 'passed', 'cash' => (string) $cash, 'closed_trade_net' => (string) $realized,
            'frames_checked' => count($curve), 'fills_checked' => count($result['fills']), 'closed_trades_checked' => count($closed)];
    }
}
