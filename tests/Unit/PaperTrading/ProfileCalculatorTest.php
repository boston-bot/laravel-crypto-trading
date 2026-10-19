<?php

namespace Tests\Unit\PaperTrading;

use App\Services\PaperTrading\ProfileCalculator;
use App\Services\PaperTrading\StrategyParameters;
use PHPUnit\Framework\TestCase;

class ProfileCalculatorTest extends TestCase
{
    /** Normalized bars for closes in cents; each bar spans close ± $half cents. Last bar is $end. */
    private function bars(array $cents, int $half = 50, string $end = '2026-10-16'): array
    {
        $money = fn (int $c) => intdiv($c, 100).'.'.str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
        $day = (new \DateTimeImmutable($end))->modify('-'.(count($cents) - 1).' days');
        $bars = [];
        foreach ($cents as $i => $c) {
            $bars[] = [$day->modify("+$i days")->format('Y-m-d'), $money($c), $money($c + $half), $money($c - $half), $money($c), '500000', $money($c), '1200'];
        }

        return $bars;
    }

    private function rising(int $count, int $from, int $step): array
    {
        return array_map(fn ($i) => $from + $i * $step, range(0, $count - 1));
    }

    private function profile(array $symbol, ?array $benchmark = null): array
    {
        return (new ProfileCalculator)->compute($symbol, $benchmark ?? $this->bars($this->rising(250, 30000, 10)), StrategyParameters::DEFAULTS);
    }

    public function test_tight_range_near_highs_in_an_uptrend_is_a_breakout_with_exact_levels(): void
    {
        $profile = $this->profile($this->bars([...$this->rising(230, 5000, 22), ...array_fill(0, 20, 10060)]));
        $this->assertSame(['complete', 'breakout', 'breakout_setup'], [$profile['status'], $profile['setup'], $profile['reason']]);
        $this->assertSame(['trigger' => '101.10', 'max_entry' => '101.6000', 'initial_stop' => '99.1000'], $profile['levels']);
        $this->assertSame('1.00000000', $profile['indicators']['atr']);
        $this->assertSame(['101.10', '100.10'], [$profile['indicators']['range_high'], $profile['indicators']['range_low']]);
        $this->assertSame('on', $profile['market']['filter']);
        // No earnings source is configured, so every setup still carries that entry blocker.
        $this->assertSame(['earnings_date_unknown'], $profile['entry_blockers']);
    }

    public function test_dip_toward_the_rising_average_is_a_pullback_triggered_above_the_prior_high(): void
    {
        $closes = [...$this->rising(245, 5000, 22), ...array_map(fn ($i) => 10368 - 50 * $i, range(1, 5))];
        $profile = $this->profile($this->bars($closes));
        $this->assertSame(['complete', 'pullback'], [$profile['status'], $profile['setup']], json_encode($profile['checks']));
        $this->assertSame('101.68', $profile['levels']['trigger']); // last close 101.18 + 0.50 high
        $this->assertFalse($profile['checks']['tight_range']);
    }

    public function test_failing_structure_gets_no_trade_with_the_first_failed_reason(): void
    {
        $falling = $this->profile($this->bars($this->rising(250, 20000, -20)));
        $this->assertSame(['complete', 'none', 'not_in_uptrend', null], [$falling['status'], $falling['setup'], $falling['reason'], $falling['levels']]);

        $laggard = $this->profile($this->bars($this->rising(250, 10000, 2)), $this->bars($this->rising(250, 30000, 30)));
        $this->assertSame(['none', 'relative_strength_weak'], [$laggard['setup'], $laggard['reason']]);

        $volatile = $this->profile($this->bars($this->rising(250, 5000, 22), 400));
        $this->assertSame(['none', 'volatility_too_high'], [$volatile['setup'], $volatile['reason']]);
    }

    public function test_falling_benchmark_turns_the_market_filter_off_without_changing_the_setup(): void
    {
        $profile = $this->profile($this->bars([...$this->rising(230, 5000, 22), ...array_fill(0, 20, 10060)]), $this->bars($this->rising(250, 40000, -10)));
        $this->assertSame(['breakout', 'off'], [$profile['setup'], $profile['market']['filter']]);
        $this->assertSame(['market_filter_off', 'earnings_date_unknown'], $profile['entry_blockers']);
    }

    public function test_short_or_misaligned_history_is_unavailable_never_a_guess(): void
    {
        $short = $this->profile($this->bars($this->rising(199, 5000, 22)));
        $this->assertSame(['unavailable', 'insufficient_history', null], [$short['status'], $short['reason'], $short['setup']]);
        $benchmarkShort = $this->profile($this->bars($this->rising(250, 5000, 22)), $this->bars($this->rising(126, 30000, 10)));
        $this->assertSame('benchmark_history_insufficient', $benchmarkShort['reason']);
        $lagging = $this->profile($this->bars($this->rising(250, 5000, 22)), $this->bars($this->rising(250, 30000, 10), end: '2026-10-15'));
        $this->assertSame('benchmark_session_mismatch', $lagging['reason']);
    }

    public function test_results_are_exact_and_reproducible(): void
    {
        $bars = $this->bars(array_fill(0, 250, 10000));
        $first = $this->profile($bars);
        $this->assertSame(['100.00000000', '100.00000000', '100.00000000'], [$first['indicators']['sma_fast'], $first['indicators']['sma_slow'], $first['indicators']['ema']]);
        $this->assertSame('0.00000000', $first['indicators']['return']);
        $this->assertSame($first, $this->profile($bars));
    }
}
