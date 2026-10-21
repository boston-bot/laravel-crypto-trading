<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Services\PaperTrading\Experiments\ReplayAccountingVerifier;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;

/** Presentation of recorded simulated fills. Never evaluates signals or invents profit targets. */
final class ExperimentTradeTable
{
    public function rows(array $result, array $terminalSession, array $settings): array
    {
        $orders = array_column($result['orders'], null, 'id');
        $zero = Decimal::from('0');
        $active = [];
        $rows = [];
        foreach ($result['fills'] as $fill) {
            $symbol = $fill['symbol'];
            $order = $orders[$fill['order_id']];
            $buy = $fill['side'] === 'BUY';
            if (! isset($active[$symbol])) {
                $active[$symbol] = ['symbol' => $symbol, 'status' => 'Open', 'setup' => null, 'quantity_bought' => $zero, 'quantity_sold' => $zero,
                    'buy_notional' => $zero, 'sell_notional' => $zero, 'fees' => $zero, 'bought_at' => $fill['at'], 'last_bought_at' => $fill['at'],
                    'sold_at' => null, 'last_sold_at' => null, 'buy_reason' => $order['reason'], 'sell_reason' => null, 'fills' => []];
            }
            $row = &$active[$symbol];
            $quantity = Decimal::from($fill['quantity']);
            $notional = $quantity->multipliedBy(Decimal::from($fill['price']));
            $row[$buy ? 'quantity_bought' : 'quantity_sold'] = $row[$buy ? 'quantity_bought' : 'quantity_sold']->plus($quantity);
            $row[$buy ? 'buy_notional' : 'sell_notional'] = $row[$buy ? 'buy_notional' : 'sell_notional']->plus($notional);
            $row['fees'] = $row['fees']->plus(Decimal::from($fill['fee']));
            $row[$buy ? 'last_bought_at' : 'last_sold_at'] = $fill['at'];
            if (! $buy) {
                $row['sell_reason'] = $order['reason'];
            }
            $row['fills'][] = $fill + ['reason' => $order['reason']];
            if ($row['quantity_bought']->compareTo($row['quantity_sold']) === 0) {
                $row['status'] = 'Closed';
                $row['sold_at'] = $fill['at'];
                $rows[] = $row;
                unset($active[$symbol]);
            }
            unset($row);
        }
        $rows = [...$rows, ...array_values($active)];
        $closed = 0;
        $frame = $terminalSession['frames'][array_key_last($terminalSession['frames'])];
        foreach ($rows as &$row) {
            $isClosed = $row['status'] === 'Closed';
            $position = $isClosed ? null : ($result['open_positions'][$row['symbol']] ?? null);
            $trade = $isClosed ? ($result['trades'][$closed++] ?? null) : null;
            $row['setup'] = $trade['setup'] ?? $position['setup'] ?? null;
            $row['pending_exit_reason'] = null;
            $row['pending_exit_status'] = null;
            if (! $isClosed) {
                foreach ($orders as $order) {
                    if (($order['symbol'] ?? null) === $row['symbol'] && ($order['side'] ?? null) === 'SELL' && isset($order['accepted_at']) && CarbonImmutable::parse($order['accepted_at'])->gte(CarbonImmutable::parse($row['bought_at']))) {
                        $row['pending_exit_reason'] = $order['reason'];
                        $row['pending_exit_status'] = $order['status'];
                    }
                }
            }
            $row['quantity_remaining'] = $row['quantity_bought']->minus($row['quantity_sold']);
            $row['buy_price'] = (string) $row['buy_notional']->dividedBy($row['quantity_bought'], 18);
            $row['sell_price'] = $row['quantity_sold']->compareTo($zero) > 0 ? (string) $row['sell_notional']->dividedBy($row['quantity_sold'], 18) : null;
            $quote = $frame['quotes'][$row['symbol']] ?? null;
            $usable = ! $isClosed && ReplayAccountingVerifier::usable($quote, $frame, $terminalSession['session'], $settings['quote_max_age_seconds']);
            $row['mark'] = $usable ? $quote['bid'] : null;
            $row['mark_at'] = $usable ? $quote['timestamp_ns'] : null;
            $row['initial_stop'] = $position['initial_stop'] ?? null;
            $row['trailing_stop'] = $position['trailing_stop'] ?? null;
            $row['stop_as_of'] = $position === null ? null : $frame['at'];
            $row['net'] = $isClosed ? (string) $row['sell_notional']->minus($row['buy_notional'])->minus($row['fees'])
                : ($usable ? (string) $row['sell_notional']->plus($row['quantity_remaining']->multipliedBy(Decimal::from($quote['bid'])))->minus($row['buy_notional'])->minus($row['fees']) : null);
            foreach (['quantity_bought', 'quantity_sold', 'quantity_remaining', 'buy_notional', 'sell_notional', 'fees'] as $key) {
                $row[$key] = (string) $row[$key];
            }
        }
        unset($row);
        usort($rows, fn ($a, $b) => strcmp($b['bought_at'], $a['bought_at']) ?: strcmp($a['symbol'], $b['symbol']));

        return $rows;
    }
}
