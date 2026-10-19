<?php

namespace Tests\Unit\PaperTrading;

use App\Services\PaperTrading\TradingCosts;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class TradingCostsTest extends TestCase
{
    public function test_reviewed_dates_thresholds_pause_cent_rounding_and_cat_are_exact(): void
    {
        $s = TradingCosts::reviewed();
        $fee = fn ($side, $q, $price) => (string) (new TradingCosts)->fee($s, $side, Decimal::from($q), Decimal::from($price));
        $this->assertSame('0.00', $fee('BUY', '1', '500.01'));
        $this->assertSame('0.00', $fee('SELL', '1', '500'));
        $this->assertSame('0.02', $fee('SELL', '1', '500.00000001'));
        $this->assertSame('0.00', $fee('SELL', '100', '5')); // SEC waiver; TAF paused
        $this->assertSame('0.00', $fee('BUY', '3333', '10'));
        $this->assertSame('0.01', $fee('BUY', '3334', '10'));
        $this->assertSame('0.03', $fee('BUY', '10000', '10'));
        $this->assertTrue(TradingCosts::valid($s, CarbonImmutable::parse('2026-12-31T15:00:00Z')));
        $this->assertFalse(TradingCosts::valid($s, CarbonImmutable::parse('2027-01-01T15:00:00Z')));
        $s['sec_per_million'] = '0';
        $this->assertFalse(TradingCosts::valid($s, CarbonImmutable::parse('2026-10-19T15:00:00Z')));
    }

    public function test_taf_rounding_and_per_execution_cap_are_explicit_when_a_future_reviewed_schedule_restores_the_rate(): void
    {
        // Calculator behavior only. This modified schedule cannot pass TradingCosts::valid or be applied by an owner.
        $s = TradingCosts::reviewed();
        $s['taf_per_share'] = '0.000195';
        $calc = new TradingCosts;
        $this->assertSame('0.00', (string) $calc->fee($s, 'SELL', Decimal::from('50'), Decimal::from('1')));
        $this->assertSame('0.01', (string) $calc->fee($s, 'SELL', Decimal::from('51'), Decimal::from('1')));
        $this->assertSame('10.30', (string) $calc->fee($s, 'SELL', Decimal::from('100000'), Decimal::from('0.1')));
    }
}
