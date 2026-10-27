<?php

namespace Tests\Unit\PaperTrading;

use App\Services\Crypto\CryptoParameters;
use App\Services\PaperTrading\RegimeRules;
use Tests\TestCase;

class RegimeRulesTest extends TestCase
{
    /** @param list<string> $closes */
    private function bars(array $closes): array
    {
        $day = new \DateTimeImmutable('2025-01-01');

        return array_map(fn ($i) => [$day->modify("+$i days")->format('Y-m-d'), $closes[$i], $closes[$i], $closes[$i], $closes[$i], '1'], array_keys($closes));
    }

    private function trend(): array
    {
        return CryptoParameters::named(CryptoParameters::REGIME_TREND)['values'];
    }

    public function test_average_signal_needs_exactly_the_lookback_and_compares_the_last_close(): void
    {
        $p = ['regime_signal' => 'sma_above', 'regime_lookback' => 3, 'vol_target_pct' => null, 'vol_window' => 30];
        $this->assertSame(3, RegimeRules::needed($p));
        $this->assertSame('regime_history_insufficient', app(RegimeRules::class)->target($this->bars(['1', '2']), $p)['reason']);
        $up = app(RegimeRules::class)->target($this->bars(['1', '2', '4']), $p);
        $this->assertSame([true, '1.000000000000000000', '2.333333333333333333'], [$up['in_trend'], $up['fraction'], $up['facts']['reference']]);
        $down = app(RegimeRules::class)->target($this->bars(['4', '2', '1']), $p);
        $this->assertSame([false, '0', 'regime_out_of_trend'], [$down['in_trend'], $down['fraction'], $down['reason']]);
    }

    public function test_return_signal_needs_one_extra_day_and_a_strictly_higher_close(): void
    {
        $p = ['regime_signal' => 'return_positive', 'regime_lookback' => 2, 'vol_target_pct' => null, 'vol_window' => 30];
        $this->assertSame(3, RegimeRules::needed($p));
        $this->assertTrue(app(RegimeRules::class)->target($this->bars(['10', '5', '11']), $p)['in_trend']);
        $this->assertFalse(app(RegimeRules::class)->target($this->bars(['10', '50', '10']), $p)['in_trend']);
    }

    public function test_volatility_target_uses_exact_log_returns_and_never_exceeds_full_allocation(): void
    {
        $p = ['regime_signal' => 'sma_above', 'regime_lookback' => 3, 'vol_target_pct' => '50', 'vol_window' => 30];
        // 31 closes alternating 110/100, ending at 110: in trend and very volatile. Reference values computed independently at 50 digits.
        $result = app(RegimeRules::class)->target($this->bars(array_map(fn ($i) => $i % 2 === 0 ? '110' : '100', range(0, 30))), $p);
        $this->assertTrue($result['in_trend']);
        $this->assertStringStartsWith('1.85202715217065866', $result['facts']['annualized_volatility']);
        $this->assertStringStartsWith('0.26997444363273920', $result['fraction']);
        // Calm prices: the volatility target would exceed 100%, so the fraction is capped at one.
        $calm = app(RegimeRules::class)->target($this->bars(array_map(fn ($i) => (string) (100 + $i), range(0, 30))), $p);
        $this->assertSame('1.000000000000000000', $calm['fraction']);
    }

    public function test_rebalance_enters_and_exits_from_flat_and_otherwise_trades_only_outside_the_band(): void
    {
        $this->assertSame('BUY', RegimeRules::rebalance('0', '0.3', '20'));
        $this->assertSame('SELL', RegimeRules::rebalance('0.05', '0', '20'));
        $this->assertNull(RegimeRules::rebalance('0', '0', '20'));
        $this->assertNull(RegimeRules::rebalance('0.5', '0.7', '20'));
        $this->assertSame('BUY', RegimeRules::rebalance('0.5', '0.700000000000000001', '20'));
        $this->assertSame('SELL', RegimeRules::rebalance('0.9', '0.6', '20'));
        // The momentum playbook's 100-point band means enter and exit only.
        $this->assertNull(RegimeRules::rebalance('0.4', '1', '100'));
        $this->assertSame(200, RegimeRules::needed($this->trend()));
        $this->assertSame(366, RegimeRules::needed(CryptoParameters::named(CryptoParameters::REGIME_MOMENTUM)['values']));
    }
}
