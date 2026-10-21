<?php

namespace Tests\Unit\PaperTrading;

use App\Services\MarketData\Alpaca\HistoricalQuotes;
use App\Services\PaperTrading\SignalParameters;
use App\Services\PaperTrading\StrategyReplay;
use App\Support\Mcp\Decimal;
use Tests\Support\IntradayReplayFixture as F;
use Tests\TestCase;

class IntradayQuoteReplayTest extends TestCase
{
    private function runScenario(?array $data = null, string $cash = '10000'): array
    {
        return app(StrategyReplay::class)->runQuotes($data ?? F::data(), SignalParameters::current()['values'], F::settings(), $cash);
    }

    public function test_later_quotes_fill_and_stop_a_position_with_exact_cash_and_no_database_ledger(): void
    {
        $r = $this->runScenario();
        $this->assertCount(2, $r['fills']);
        $this->assertCount(1, $r['trades']);
        $this->assertSame('initial_stop_hit', $r['trades'][0]['reason']);
        $this->assertSame([], $r['open_positions']);
        $cash = Decimal::from('10000');
        foreach ($r['fills'] as $fill) {
            $amount = Decimal::from($fill['price'])->multipliedBy(Decimal::from($fill['quantity']));
            $cash = ($fill['side'] === 'BUY' ? $cash->minus($amount) : $cash->plus($amount))->minus(Decimal::from($fill['fee']));
            $this->assertGreaterThan(HistoricalQuotes::timestamp($r['orders'][$fill['order_id'] - 1]['accepted_at']), $fill['source_ns']);
        }
        $this->assertSame(0, $cash->compareTo(Decimal::from($r['final_equity'])));
        $this->assertSame($r, $this->runScenario());
    }

    public function test_same_or_future_quote_cannot_fill_a_new_order_and_pending_cash_is_reserved(): void
    {
        $at = '2026-10-19T13:45:00Z';
        $q = F::quote($at);
        $frames = [F::frame($at), F::frame('2026-10-19T13:45:01Z', 'fill', $q)];
        $r = $this->runScenario(F::data($frames));
        $this->assertSame([], $r['fills']);
        $this->assertSame('open', $r['orders'][0]['status']);
        $this->assertGreaterThan(0, Decimal::from($r['orders'][0]['reserved_cash'])->compareTo(Decimal::from('0')));
        $future = F::quote('2026-10-19T13:45:01Z');
        $future['timestamp_ns'] = '2026-10-19T13:45:01.000000001Z';
        $frames[1] = F::frame('2026-10-19T13:45:01Z', 'fill', $future);
        $this->assertSame([], $this->runScenario(F::data($frames))['fills']);
    }

    public function test_displayed_sizes_bound_partial_fills_and_duplicate_liquidity_is_not_reused(): void
    {
        $q = F::quote('2026-10-19T13:45:01Z', size: '3');
        $frames = [F::frame('2026-10-19T13:45:00Z'), F::frame('2026-10-19T13:45:01Z', 'fill', $q), F::frame('2026-10-19T13:45:02Z', 'fill', $q)];
        $r = $this->runScenario(F::data($frames));
        $this->assertCount(1, $r['fills']);
        $this->assertSame('3', $r['fills'][0]['quantity']);
        $this->assertSame('partial', $r['orders'][0]['status']);
        $this->assertSame('3', $r['open_positions']['DEMO']['quantity']);
    }

    public function test_early_entries_wide_spreads_zero_size_and_unmarketable_limits_are_not_filled(): void
    {
        $r = $this->runScenario(F::data([F::frame('2026-10-19T13:35:00Z')]));
        $this->assertSame('opening_entry_delay', $r['events'][0]['reason']);
        $this->assertSame([], $r['orders']);
        $this->assertSame([], $this->runScenario(cash: '500')['orders']);
        $frames = [F::frame('2026-10-19T13:45:00Z', quote: F::quote('2026-10-19T13:45:00Z', '100', '102'))];
        $this->assertSame('eligibility_threshold_failed', $this->runScenario(F::data($frames))['events'][0]['reason']);
        $frames = [F::frame('2026-10-19T13:45:00Z'), F::frame('2026-10-19T13:45:01Z', 'fill', F::quote('2026-10-19T13:45:01Z', '101.30', '101.40'))];
        $this->assertSame([], $this->runScenario(F::data($frames))['fills']);
    }

    public function test_missing_final_marks_make_equity_and_return_unknown_instead_of_using_entry_prices(): void
    {
        $frames = [F::frame('2026-10-19T13:45:00Z'), F::frame('2026-10-19T13:45:01Z', 'fill'),
            ['at' => '2026-10-19T13:50:00Z', 'kind' => 'mark', 'quotes' => ['DEMO' => null]]];
        $r = $this->runScenario(F::data($frames));
        $this->assertNull($r['final_equity']);
        $this->assertNull($r['stats']['total_return_pct']);
        $this->assertNotEmpty($r['open_positions']);
    }

    public function test_current_and_future_daily_bars_never_change_prior_session_profiles(): void
    {
        $data = F::data();
        $baseline = $this->runScenario($data);
        $data['daily_reads']['DEMO']['bars'][] = ['2026-10-19', '999', '999', '999', '999', '99999999', '999', '99'];
        $data['daily_reads']['SPY']['bars'][] = ['2026-10-20', '1', '1', '1', '1', '1', '1', '1'];
        $this->assertSame($baseline, $this->runScenario($data));
    }

    public function test_a_final_valuation_quote_cannot_fill_orders_and_full_day_orders_expire_without_a_fill(): void
    {
        $frames = [F::frame('2026-10-19T13:45:00Z'), F::frame('2026-10-19T19:59:59Z', 'mark')];
        $data = F::data($frames);
        $data['window_end'] = '2026-10-19T20:00:00Z';
        $r = $this->runScenario($data);
        $this->assertSame([], $r['fills']);
        $this->assertSame('expired', $r['orders'][0]['status']);
        $this->assertSame('0', $r['orders'][0]['reserved_cash']);
        $this->assertSame('10000.000000000000000000', $r['final_equity']);
    }
}
