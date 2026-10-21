<?php

namespace Tests\Unit\PaperTrading;

use App\Services\PaperTrading\ExperimentTradeTable;
use Tests\Support\IntradayReplayFixture as F;
use Tests\TestCase;

class ExperimentTradeTableTest extends TestCase
{
    private function data(): array
    {
        $fills = [];
        foreach ([['BUY', '2', '10', '13:45:00'], ['BUY', '1', '13', '13:50:00'], ['SELL', '1', '15', '14:00:00']] as [$side, $quantity, $price, $time]) {
            $fills[] = ['symbol' => 'DEMO', 'side' => $side, 'quantity' => $quantity, 'price' => $price, 'fee' => '0.01',
                'order_id' => $side === 'BUY' ? 1 : 2, 'at' => '2026-10-19T'.$time.'Z'];
        }
        $result = ['orders' => [['id' => 1, 'reason' => 'pullback_trigger_crossed'], ['id' => 2, 'reason' => 'initial_stop_hit']], 'fills' => $fills, 'trades' => [],
            'open_positions' => ['DEMO' => ['setup' => 'pullback', 'initial_stop' => '9', 'trailing_stop' => '10']]];
        $data = F::data([F::frame('2026-10-19T19:59:59Z', 'mark', F::quote('2026-10-19T19:59:59Z', '14', '14.01'))]);

        return [$result, $data];
    }

    public function test_partial_fills_and_partial_sales_form_one_open_episode_with_weighted_prices_and_exact_marked_pnl(): void
    {
        [$result, $data] = $this->data();
        $rows = app(ExperimentTradeTable::class)->rows($result, $data, F::settings());
        $this->assertCount(1, $rows);
        $r = $rows[0];
        $this->assertSame('Open', $r['status']);
        $this->assertSame('11.000000000000000000', $r['buy_price']);
        $this->assertSame('15.000000000000000000', $r['sell_price']);
        $this->assertSame('2', $r['quantity_remaining']);
        $this->assertSame('9.97', $r['net']);
        $this->assertSame('0.03', $r['fees']);
        $this->assertSame('9', $r['initial_stop']);
        $this->assertSame('10', $r['trailing_stop']);
        $this->assertNull($r['sold_at']);
        $this->assertSame('2026-10-19T14:00:00Z', $r['last_sold_at']);
        $this->assertSame('2026-10-19T13:45:00Z', $r['bought_at']);
    }

    public function test_closing_and_reentering_the_same_ticker_creates_separate_episodes_without_mixing_reasons_or_stops(): void
    {
        [$r, $data] = $this->data();
        $r['fills'][] = ['symbol' => 'DEMO', 'side' => 'SELL', 'quantity' => '2', 'price' => '14', 'fee' => '0.01', 'order_id' => 2, 'at' => '2026-10-19T14:05:00Z'];
        $r['fills'][] = ['symbol' => 'DEMO', 'side' => 'BUY', 'quantity' => '1', 'price' => '13', 'fee' => '0.01', 'order_id' => 3, 'at' => '2026-10-19T15:00:00Z'];
        $r['orders'][] = ['id' => 3, 'reason' => 'breakout_trigger_crossed'];
        $r['trades'] = [['setup' => 'pullback']];
        $r['open_positions']['DEMO'] = ['setup' => 'breakout', 'initial_stop' => '12', 'trailing_stop' => '12.5'];
        [$open, $closed] = app(ExperimentTradeTable::class)->rows($r, $data, F::settings());
        $this->assertSame('breakout', $open['setup']);
        $this->assertSame('breakout_trigger_crossed', $open['buy_reason']);
        $this->assertSame('0.99', $open['net']);
        $this->assertSame('Closed', $closed['status']);
        $this->assertSame('pullback', $closed['setup']);
        $this->assertSame('9.96', $closed['net']);
        $this->assertSame('2026-10-19T14:05:00Z', $closed['sold_at']);
        $this->assertNull($closed['initial_stop']);
        $this->assertNull($closed['trailing_stop']);
        $this->assertSame('initial_stop_hit', $closed['sell_reason']);
    }

    public function test_missing_or_stale_terminal_marks_leave_open_pnl_unknown_and_unfilled_intents_do_not_become_trades(): void
    {
        [$r, $data] = $this->data();
        $data['frames'][0]['quotes']['DEMO']['timestamp_ns'] = '2026-10-19T19:55:00.000000000Z';
        $row = app(ExperimentTradeTable::class)->rows($r, $data, F::settings())[0];
        $this->assertNull($row['mark']);
        $this->assertNull($row['net']);
        $this->assertNull($row['sold_at']);
        $r['fills'] = [];
        $r['open_positions'] = [];
        $this->assertSame([], app(ExperimentTradeTable::class)->rows($r, $data, F::settings()));
    }

    public function test_an_unfilled_exit_intent_is_visible_without_inventing_a_sale_price_or_time(): void
    {
        [$r, $data] = $this->data();
        $r['fills'] = array_slice($r['fills'], 0, 2);
        $r['orders'][1] += ['symbol' => 'DEMO', 'side' => 'SELL', 'status' => 'expired', 'accepted_at' => '2026-10-19T13:50:00.000000Z'];
        $row = app(ExperimentTradeTable::class)->rows($r, $data, F::settings())[0];
        $this->assertSame('initial_stop_hit', $row['pending_exit_reason']);
        $this->assertSame('expired', $row['pending_exit_status']);
        $this->assertNull($row['sell_price']);
        $this->assertNull($row['sold_at']);
        $this->assertSame('Open', $row['status']);
    }
}
