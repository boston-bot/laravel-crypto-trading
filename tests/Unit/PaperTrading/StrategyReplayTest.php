<?php

namespace Tests\Unit\PaperTrading;

use App\Services\PaperTrading\SignalParameters;
use App\Services\PaperTrading\StrategyReplay;
use App\Services\PaperTrading\TradingCosts;
use Tests\TestCase;

class StrategyReplayTest extends TestCase
{
    private function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param list<array{0:int,1:int,2:int,3:int}> $ohlc cents per day; dates are consecutive calendar days */
    private function bars(array $ohlc): array
    {
        $day = new \DateTimeImmutable('2025-01-01');
        $bars = [];
        foreach ($ohlc as $i => [$o, $h, $l, $c]) {
            $bars[] = [$day->modify("+$i days")->format('Y-m-d'), $this->money($o), $this->money($h), $this->money($l), $this->money($c), '500000', $this->money($c), '1200'];
        }

        return $bars;
    }

    /** 230 rising sessions, then 20 flat sessions in a tight range at 100.60: a breakout setup with trigger 101.10. */
    private function base(): array
    {
        $rows = [];
        for ($i = 0; $i < 230; $i++) {
            $c = 5000 + $i * 22;
            $rows[] = [$c, $c + 50, $c - 50, $c];
        }
        for ($i = 0; $i < 20; $i++) {
            $rows[] = [10060, 10110, 10010, 10060];
        }

        return $rows;
    }

    private function benchmark(int $count, int $step = 10): array
    {
        return $this->bars(array_map(fn ($i) => [30000 + $i * $step, 30000 + $i * $step + 50, 30000 + $i * $step - 50, 30000 + $i * $step], range(0, $count - 1)));
    }

    private function replay(array $rows, string $equity = '10000', ?array $spy = null): array
    {
        $settings = ['risk_per_trade_pct' => '1', 'max_position_pct' => '20', 'max_open_positions' => 8, 'slippage_bps' => '1', 'cost_schedule' => TradingCosts::reviewed()];

        return app(StrategyReplay::class)->run(['BRKO' => $this->bars($rows)], $spy ?? $this->benchmark(count($rows)), SignalParameters::current()['values'], $settings, $equity);
    }

    private function universeReplay(array $rows, int $maxOpen = 1): array
    {
        return app(StrategyReplay::class)->run(array_map($this->bars(...), $rows), $this->benchmark(count(reset($rows))), SignalParameters::current()['values'],
            ['risk_per_trade_pct' => '1', 'max_position_pct' => '20', 'max_open_positions' => $maxOpen, 'slippage_bps' => '1', 'cost_schedule' => TradingCosts::reviewed()], '10000');
    }

    public function test_intraday_exits_cannot_fund_or_free_capacity_for_that_sessions_entries(): void
    {
        foreach ([10120, 10080] as $secondOpen) { // Opening and intraday candidates must both wait.
            $a = [...$this->base(), [10080, 10150, 10050, 10140], [10080, 10100, 9800, 10050], [10050, 10100, 10000, 10050]];
            $b = [...$this->base(), [10060, 10110, 10010, 10060], [$secondOpen, 10150, 10050, 10140], [10160, 10180, 10100, 10170]];
            $result = $this->universeReplay(['AAAA' => $a, 'BBBB' => $b]);
            $this->assertSame('intraday_stop_hit', $result['trades'][0]['reason']);
            $this->assertSame('2025-09-10', $result['open_positions']['BBBB']['entry_date']);
            $this->assertGreaterThan(0, $result['counts']['skipped_capacity']);
        }
    }

    public function test_entry_day_stops_do_not_recycle_cash_or_capacity_to_other_candidates(): void
    {
        $a = [...$this->base(), [10080, 10150, 9800, 9850]];
        $b = [...$this->base(), [10080, 10150, 10050, 10140]];
        $result = $this->universeReplay(['AAAA' => $a, 'BBBB' => $b]);
        $this->assertSame(1, $result['counts']['entries']);
        $this->assertSame('entry_day_stop_assumed', $result['trades'][0]['reason']);
        $this->assertSame([], $result['open_positions']);
    }

    public function test_intraday_sale_proceeds_are_unavailable_even_when_position_slots_remain(): void
    {
        $held = [...$this->base(), [10080, 10150, 10050, 10140], [10080, 10150, 10050, 10140], [10140, 10180, 10100, 10170]];
        $stopped = $held;
        $stopped[251] = [10080, 10100, 9800, 10050];
        $expensive = [...$this->base(), [10060, 10110, 10010, 10060], [10120, 10150, 10050, 10140], [10160, 10180, 10100, 10170]];
        $expensive = array_map(fn ($row) => array_map(fn ($cents) => $cents * 10, $row), $expensive);
        // Five 19-share positions leave less cash than one share of ZZZZ; only AAAA stops out.
        $result = $this->universeReplay(['AAAA' => $stopped, 'BBBB' => $held, 'CCCC' => $held, 'DDDD' => $held, 'EEEE' => $held, 'ZZZZ' => $expensive], 8);
        $this->assertSame('2025-09-10', $result['open_positions']['ZZZZ']['entry_date']);
        $this->assertGreaterThan(0, $result['counts']['skipped_zero_size']);
    }

    public function test_opening_entries_take_capacity_before_alphabetically_earlier_intraday_entries(): void
    {
        $intraday = [...$this->base(), [10080, 10150, 10050, 10140]];
        $opening = [...$this->base(), [10120, 10150, 10050, 10140]];
        $result = $this->universeReplay(['AAAA' => $intraday, 'ZZZZ' => $opening]);
        $this->assertSame(['ZZZZ'], array_keys($result['open_positions']));
    }

    public function test_opening_gap_losses_reduce_equity_before_sizing_replacement_entries(): void
    {
        $a = [...$this->base(), [10080, 10150, 10050, 10140], [5000, 5100, 4900, 5000]];
        $b = [...$this->base(), [10060, 10110, 10010, 10060], [10120, 10150, 10050, 10140]];
        $result = $this->universeReplay(['AAAA' => $a, 'BBBB' => $b]);
        $this->assertSame('initial_stop_hit', $result['trades'][0]['reason']);
        // Equity after the gap sale and fees is $9,028.6012. Its 20% cap buys 17 shares, not 19.
        $this->assertSame('17', $result['open_positions']['BBBB']['quantity']);
        $this->assertSame('2025-09-09', $result['open_positions']['BBBB']['entry_date']);
    }

    public function test_warmup_and_post_window_bars_do_not_trade_or_enter_performance(): void
    {
        $rows = [...$this->base(), [10080, 10150, 10050, 10140], [10060, 10110, 10010, 10060], [10060, 10110, 10010, 10060], [10080, 10200, 9800, 9850]];
        $settings = ['risk_per_trade_pct' => '1', 'max_position_pct' => '20', 'max_open_positions' => 8, 'slippage_bps' => '1', 'cost_schedule' => TradingCosts::reviewed()];
        $result = app(StrategyReplay::class)->run(['BRKO' => $this->bars($rows)], $this->benchmark(count($rows)), SignalParameters::current()['values'], $settings, '10000', '2025-09-09', '2025-09-10');
        $this->assertSame(['2025-09-09', '2025-09-10', 2], [$result['start'], $result['end'], $result['sessions']]);
        $this->assertSame([0, [], [], '10000.00'], [$result['counts']['entries'], $result['trades'], $result['open_positions'], $result['final_equity']]);
        $this->assertSame(['2025-09-09', '2025-09-10'], array_column($result['equity_curve'], 0));
    }

    public function test_breakout_enters_the_session_after_its_setup_and_exits_on_the_live_trailing_stop(): void
    {
        $rows = $this->base();
        $rows[] = [10080, 10150, 10050, 10140];            // crosses 101.10 intraday: entry at 101.11 plus slippage
        foreach ([10400, 10700, 11000] as $c) {
            $rows[] = [$c - 50, $c + 50, $c - 100, $c];   // rally; the trailing stop rises to 110.00 - 3 x 1.00 = 107.00
        }
        $rows[] = [10950, 10980, 10650, 10700];             // low 106.50 breaks the 107.00 trailing stop
        $replay = $this->replay($rows);
        $this->assertSame(1, $replay['counts']['entries']);
        $trade = $replay['trades'][0];
        $this->assertSame(['breakout', '2025-09-08', '101.1202', '2025-09-12', 'intraday_stop_hit'], [$trade['setup'], $trade['entry_date'], $trade['entry_price'], $trade['exit_date'], $trade['reason']]);
        $this->assertSame('106.9893', $trade['exit_price']); // 107.00 less 1 bp, rounded down
        $this->assertSame('19', $trade['quantity']);        // 20% of $10,000 caps the 1%-risk size
        $this->assertSame(1, $replay['stats']['wins']);
        $this->assertSame([], $replay['open_positions']);
        $this->assertContains('earnings_blackout_not_reconstructed', $replay['limitations']);
    }

    public function test_small_accounts_record_whole_share_size_zero_instead_of_trading(): void
    {
        $rows = $this->base();
        $rows[] = [10080, 10150, 10050, 10140];
        $replay = $this->replay($rows, '500');
        $this->assertSame([0, 1, []], [$replay['counts']['entries'], $replay['counts']['skipped_zero_size'], $replay['trades']]);
        $this->assertSame('500.00', $replay['final_equity']);
    }

    public function test_gaps_beyond_the_chase_limit_and_a_falling_market_never_enter(): void
    {
        $rows = $this->base();
        $rows[] = [10200, 10300, 10150, 10250];             // opens above the 101.60 maximum entry
        $gap = $this->replay($rows);
        // Earlier setup days did not cross the trigger either; the gap day adds one more skip, never an entry.
        $this->assertSame([0, []], [$gap['counts']['entries'], $gap['trades']]);
        $this->assertSame($this->replay(array_slice($rows, 0, -1))['counts']['skipped_chase_or_no_cross'] + 1, $gap['counts']['skipped_chase_or_no_cross']);
        $rows = $this->base();
        $rows[] = [10080, 10150, 10050, 10140];
        $falling = $this->replay($rows, '10000', $this->benchmark(count($rows), -10));
        $this->assertSame(0, $falling['counts']['entries']);
        $this->assertGreaterThan(0, $falling['counts']['market_filter_off_days']);
    }

    public function test_entry_day_lows_through_the_stop_assume_the_worse_ordering(): void
    {
        $rows = $this->base();
        $rows[] = [10080, 10150, 9800, 9850];                // trades through 101.11, then falls below the 99.12 stop
        $replay = $this->replay($rows);
        $this->assertSame(['entry_day_stop_assumed', '2025-09-08', '2025-09-08'], [$replay['trades'][0]['reason'], $replay['trades'][0]['entry_date'], $replay['trades'][0]['exit_date']]);
        $this->assertSame(0, $replay['stats']['wins']);
        $this->assertSame($replay, $this->replay($rows)); // deterministic, exact decimals
    }
}
