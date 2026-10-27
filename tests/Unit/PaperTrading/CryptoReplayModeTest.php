<?php

namespace Tests\Unit\PaperTrading;

use App\Services\Crypto\CryptoParameters;
use App\Services\PaperTrading\StrategyReplay;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Tests\TestCase;

class CryptoReplayModeTest extends TestCase
{
    private function bars(array $ohlc, string $volume = '500000'): array
    {
        $day = new \DateTimeImmutable('2025-01-01');
        $bars = [];
        foreach ($ohlc as $i => [$o, $h, $l, $c]) {
            $bars[] = [$day->modify("+$i days")->format('Y-m-d'), ...array_map(fn ($v) => (string) Decimal::from((string) $v)->dividedBy(Decimal::from('100'), 2), [$o, $h, $l, $c]), $volume];
        }

        return $bars;
    }

    /** 230 rising days, then 20 flat days in a tight range at 100.60, then a breakout and continuation. */
    private function rows(array $after = []): array
    {
        $rows = [];
        for ($i = 0; $i < 230; $i++) {
            $c = 5000 + $i * 22;
            $rows[] = [$c, $c + 50, $c - 50, $c];
        }
        for ($i = 0; $i < 20; $i++) {
            $rows[] = [10060, 10110, 10010, 10060];
        }

        return [...$rows, [10080, 10150, 10050, 10140], [10140, 10200, 10120, 10190], [10190, 10260, 10170, 10250], ...$after];
    }

    private function replay(string $volume = '500000', array $after = []): array
    {
        $rows = $this->rows($after);
        $benchmark = $this->bars(array_map(fn ($i) => [30000 + $i * 10, 30050 + $i * 10, 29950 + $i * 10, 30000 + $i * 10], range(0, count($rows) - 1)));
        $settings = ['order_mode' => 'crypto_limit', 'profile_window' => 200, 'min_daily_quote_volume' => '10000000', 'slippage_bps' => '10',
            'instruments' => ['ALT-USD' => ['base_increment' => '0.0001', 'price_increment' => '0.01', 'base_min_size' => '0.0001', 'base_max_size' => '1000',
                'quote_min_size' => '1', 'quote_max_size' => '1000000']],
            'cost_schedule' => ['kind' => 'venue_notional_rate_v1', 'maker_rate' => '0.005', 'taker_rate' => '0.009'],
            'risk_per_trade_pct' => '5', 'max_position_pct' => '25', 'max_open_positions' => 3];

        return app(StrategyReplay::class)->run(['ALT-USD' => $this->bars($rows, $volume)], $benchmark, CryptoParameters::current()['values'], $settings, '400');
    }

    public function test_crypto_mode_uses_venue_increments_instrument_sizing_and_the_full_live_window(): void
    {
        $result = $this->replay();
        // Replay starts only once a full 200-day profile window exists (2025-01-01 + 200 days).
        $this->assertSame('2025-07-20', $result['start']);
        // The intraday cross is one price increment through the 101.10 trigger, plus 10 bp, rounded up to the 0.01 tick.
        $this->assertSame(['0.9879', '101.22', '2025-09-08', 'breakout'], array_values($result['open_positions']['ALT-USD']));
        // The 25% position cap ($100 of $400) binds; quantity rounds down to the 0.0001 base increment.
        $this->assertSame(1, $result['counts']['entries']);
        $this->assertContains('daily_loss_pause_not_modeled', $result['limitations']);
        $this->assertNotContains('earnings_blackout_not_reconstructed', $result['limitations']);
    }

    public function test_stop_exit_rounds_down_to_the_tick_and_charges_venue_fees(): void
    {
        $result = $this->replay(after: [[10190, 10200, 9500, 9600]]);
        $trade = $result['trades'][0];
        $this->assertSame('intraday_stop_hit', $trade['reason']);
        $this->assertSame(0, Decimal::from($trade['exit_price'])->compareTo(Decimal::from($trade['exit_price'])->rounded(2, RoundingMode::DOWN)));
        $this->assertSame(-1, Decimal::from($trade['net'])->compareTo(Decimal::from('0')));
        $this->assertSame(1, $result['stats']['closed_trades']);
    }

    public function test_prior_day_quote_volume_below_the_live_minimum_blocks_entries(): void
    {
        $result = $this->replay('1000');
        $this->assertSame(0, $result['counts']['entries']);
        $this->assertGreaterThan(0, $result['counts']['skipped_liquidity']);
    }
}
