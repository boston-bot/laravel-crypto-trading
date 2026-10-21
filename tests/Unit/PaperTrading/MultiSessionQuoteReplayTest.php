<?php

namespace Tests\Unit\PaperTrading;

use App\Services\PaperTrading\SignalParameters;
use App\Services\PaperTrading\StrategyReplay;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Tests\Support\IntradayReplayFixture as F;
use Tests\TestCase;

class MultiSessionQuoteReplayTest extends TestCase
{
    private function sessions(bool $missingOpen = false): array
    {
        $one = F::data([F::frame('2026-10-19T13:30:00.000000Z'), F::frame('2026-10-19T13:45:00Z'),
            F::frame('2026-10-19T13:50:00Z', 'fill'), F::frame('2026-10-19T19:59:59Z', 'mark')]);
        $one['frames'][0]['quotes']['DEMO']['timestamp_ns'] = '2026-10-19T13:30:00.000000000Z';
        $one['session']['opens_at'] = '2026-10-19T13:30:00.000000Z';
        $one['window_end'] = $one['session']['closes_at'];
        $one['action_reads'] = ['DEMO' => [['complete' => true, 'events' => []]], 'SPY' => [['complete' => true, 'events' => []]]];
        $two = $one;
        $two['date'] = $two['session']['date'] = '2026-10-20';
        $two['previous_session'] = '2026-10-19';
        foreach (['opens_at', 'closes_at'] as $key) {
            $two['session'][$key] = str_replace('19T', '20T', $one['session'][$key]);
        }
        $two['window_end'] = $two['session']['closes_at'];
        foreach ($two['daily_reads'] as &$read) {
            $bar = $read['bars'][array_key_last($read['bars'])];
            $bar[0] = '2026-10-19';
            $read['bars'][] = $bar;
        }
        unset($read);
        $two['frames'] = [F::frame('2026-10-20T13:30:00.000000Z'),
            F::frame('2026-10-20T13:35:00Z', quote: F::quote('2026-10-20T13:35:00Z', '98', '98.10')),
            F::frame('2026-10-20T13:40:00Z', 'fill', F::quote('2026-10-20T13:40:00Z', '98', '98.10')),
            F::frame('2026-10-20T19:59:59Z', 'mark')];
        $two['frames'][0]['quotes']['DEMO']['timestamp_ns'] = '2026-10-20T13:30:00.000000000Z';
        if ($missingOpen) {
            $two['frames'][0]['quotes']['DEMO'] = null;
        }

        return [$one, $two];
    }

    private function replay(array $data, ?array $parameters = null): array
    {
        return app(StrategyReplay::class)->runQuoteSessions($data, $parameters ?? SignalParameters::current()['values'], F::settings(), '10000');
    }

    public function test_positions_cash_and_pinned_stops_survive_the_close_and_close_on_later_quotes(): void
    {
        $r = $this->replay($this->sessions());
        $this->assertSame(StrategyReplay::MULTISESSION_VERSION, $r['version']);
        $this->assertCount(2, $r['fills']);
        $this->assertCount(1, $r['trades']);
        $this->assertSame('2026-10-19', $r['trades'][0]['entry_date']);
        $this->assertSame('2026-10-20', $r['trades'][0]['exit_date']);
        $this->assertSame(1, $r['sessions'][0]['fills']);
        $this->assertSame(1, $r['sessions'][1]['closed_trades']);
        $this->assertSame([], $r['open_positions']);
        $cash = Decimal::from('10000');
        foreach ($r['fills'] as $fill) {
            $amount = Decimal::from($fill['price'])->multipliedBy(Decimal::from($fill['quantity']));
            $cash = ($fill['side'] === 'BUY' ? $cash->minus($amount) : $cash->plus($amount))->minus(Decimal::from($fill['fee']));
        }
        $this->assertSame(0, $cash->compareTo(Decimal::from($r['cash'])));
        $this->assertSame(0, $cash->minus(Decimal::from('10000'))->compareTo(Decimal::from($r['closed_trade_net'])));
        $this->assertSame(0, Decimal::from($r['open_episode_marked_pnl'])->compareTo(Decimal::from('0')));
        $this->assertSame($r, $this->replay($this->sessions()));
    }

    public function test_missing_opening_marks_block_entries_but_never_block_a_held_position_stop(): void
    {
        $r = $this->replay($this->sessions(true));
        $this->assertNull($r['sessions'][1]['opening_equity']);
        $this->assertTrue($r['sessions'][1]['daily_loss_paused']);
        $this->assertCount(1, $r['trades']);
    }

    public function test_day_orders_expire_without_being_filled_by_the_next_open(): void
    {
        $data = $this->sessions();
        $data[0]['frames'] = [$data[0]['frames'][0], $data[0]['frames'][1], $data[0]['frames'][3]];
        $data[1]['frames'] = [$data[1]['frames'][0], $data[1]['frames'][3]];
        $r = $this->replay($data);
        $this->assertSame('expired', $r['orders'][0]['status']);
        $this->assertSame('0', $r['orders'][0]['reserved_cash']);
        $this->assertSame([], $r['fills']);
        $this->assertSame('10000', $r['cash']);
    }

    public function test_completed_close_updates_time_exit_without_using_the_current_session_close(): void
    {
        $data = $this->sessions();
        $p = SignalParameters::current()['values'];
        $p['time_exit_sessions'] = 1;
        $data[1]['frames'] = [$data[1]['frames'][0], $data[1]['frames'][3]];
        $r = $this->replay($data, $p);
        $today = array_values(array_filter($r['events'], fn ($e) => str_starts_with($e['at'], '2026-10-20')));
        $this->assertSame('time_exit_without_progress', $today[0]['reason']);
        $this->assertSame(1, $r['sessions'][1]['open_positions']['DEMO']['sessions_held']);
        $data[1]['daily_reads']['DEMO']['bars'][] = ['2026-10-20', '1000', '1000', '1', '1000', '99999999', '1000', '1'];
        $this->assertSame($r, $this->replay($data, $p));
    }

    public function test_skipped_sessions_incomplete_inputs_and_unhandled_dividends_produce_no_performance(): void
    {
        foreach (['gap', 'incomplete', 'dividend', 'missing_actions'] as $case) {
            $data = $this->sessions();
            if ($case === 'gap') {
                $data[1]['previous_session'] = '2026-10-16';
            } elseif ($case === 'incomplete') {
                $data[1]['complete'] = false;
            } elseif ($case === 'missing_actions') {
                unset($data[1]['action_reads']);
            } else {
                $data[1]['action_reads']['DEMO'][0]['events'][] = ['type' => 'cash_dividends', 'dates' => ['ex_date' => '2026-10-20']];
            }
            try {
                $this->replay($data);
                $this->fail('Unsupported replay returned performance: '.$case);
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_partial_buy_remainder_expires_while_its_filled_shares_carry_and_sell_once(): void
    {
        $data = $this->sessions();
        $data[0]['frames'][2]['quotes']['DEMO']['ask_size'] = '3';
        $r = $this->replay($data);
        $this->assertSame('expired', $r['orders'][0]['status']);
        $this->assertSame('3', $r['orders'][0]['filled_quantity']);
        $this->assertSame('3', $r['sessions'][0]['open_positions']['DEMO']['quantity']);
        $this->assertCount(1, $r['trades']);
        $this->assertSame('3', $r['trades'][0]['quantity']);
        $this->assertSame([], $r['open_positions']);
    }

    public function test_completed_close_raises_the_trailing_stop_without_changing_entry_atr(): void
    {
        $data = $this->sessions();
        $data[1]['daily_reads']['DEMO']['bars'][array_key_last($data[1]['daily_reads']['DEMO']['bars'])][4] = '110';
        $data[1]['frames'] = [$data[1]['frames'][0], $data[1]['frames'][3]];
        $r = $this->replay($data);
        $old = $r['sessions'][0]['open_positions']['DEMO'];
        $now = $r['sessions'][1]['open_positions']['DEMO'];
        $this->assertSame($old['entry_atr'], $now['entry_atr']);
        $this->assertSame($old['initial_stop'], $now['initial_stop']);
        $this->assertGreaterThan(0, Decimal::from($now['trailing_stop'])->compareTo(Decimal::from($old['trailing_stop'])));
        $this->assertTrue($now['progressed']);
        $today = array_values(array_filter($r['events'], fn ($e) => str_starts_with($e['at'], '2026-10-20')));
        $this->assertSame('trailing_stop_hit', $today[0]['reason']);
    }

    public function test_daily_loss_pause_uses_a_new_opening_equity_instead_of_the_original_starting_cash(): void
    {
        $data = $this->sessions();
        // A large overnight gap is present in the new opening mark, not a loss since that opening.
        $data[1]['frames'][0]['quotes']['DEMO']['bid'] = '80';
        $data[1]['frames'][0]['quotes']['DEMO']['ask'] = '80.10';
        $data[1]['frames'] = [$data[1]['frames'][0], F::frame('2026-10-20T19:59:59Z', 'mark', F::quote('2026-10-20T19:59:59Z', '80', '80.10'))];
        $r = $this->replay($data);
        $this->assertLessThan(9800, (int) $r['sessions'][1]['opening_equity']);
        $this->assertFalse($r['sessions'][1]['daily_loss_paused']);
        $data[1]['frames'][1]['quotes']['DEMO']['bid'] = '60';
        $data[1]['frames'][1]['quotes']['DEMO']['ask'] = '60.10';
        $this->assertTrue($this->replay($data)['sessions'][1]['daily_loss_paused']);
    }

    public function test_a_loss_from_a_partial_fill_latches_the_pause_even_if_the_next_quote_recovers(): void
    {
        $data = $this->sessions();
        $data[0]['frames'][2]['quotes']['DEMO']['ask_size'] = '10';
        array_splice($data[0]['frames'], 3, 0, [F::frame('2026-10-19T13:55:00Z', 'fill', F::quote('2026-10-19T13:55:00Z', '101.23', '101.24'))]);
        $settings = F::settings();
        $settings['daily_loss_pause_pct'] = '0.01';
        $r = app(StrategyReplay::class)->runQuoteSessions($data, SignalParameters::current()['values'], $settings, '10000');
        $this->assertTrue($r['sessions'][0]['daily_loss_paused']);
        $this->assertSame(1, $r['sessions'][0]['fills']);
        $this->assertSame('10', $r['orders'][0]['filled_quantity']);
        $this->assertSame('expired', $r['orders'][0]['status']);
    }

    public function test_experiment_windows_extend_beyond_five_sessions_without_restarting_the_cash_or_inventory(): void
    {
        $data = $this->sessions();
        while (count($data) < 7) {
            $prior = $data[array_key_last($data)];
            $day = CarbonImmutable::parse($prior['date'])->addWeekday();
            $next = $prior;
            $next['date'] = $next['session']['date'] = $day->toDateString();
            $next['previous_session'] = $prior['date'];
            foreach (['opens_at', 'closes_at'] as $key) {
                $next['session'][$key] = str_replace($prior['date'], $next['date'], $prior['session'][$key]);
            }
            $next['window_end'] = $next['session']['closes_at'];
            foreach ($next['daily_reads'] as &$read) {
                $bar = $read['bars'][array_key_last($read['bars'])];
                $bar[0] = $prior['date'];
                $read['bars'][] = $bar;
            }
            unset($read);
            $next['frames'] = array_map(fn ($f) => F::frame(str_replace($prior['date'], $next['date'], $f['at']), $f['kind']), $prior['frames']);
            $data[] = $next;
        }
        $r = app(StrategyReplay::class)->runQuoteWindow($data, SignalParameters::current()['values'], F::settings(), '10000');
        $this->assertCount(7, $r['sessions']);
        $this->assertSame('intraday_experiment_v1', $r['version']);
        $this->assertSame('2026-10-19', $r['trades'][0]['entry_date']);
        $this->assertSame('2026-10-20', $r['trades'][0]['exit_date']);
        $this->assertNotSame('10000', $r['sessions'][2]['cash']);
        $this->expectException(\InvalidArgumentException::class);
        app(StrategyReplay::class)->runQuoteSessions($data, SignalParameters::current()['values'], F::settings(), '10000');
    }
}
