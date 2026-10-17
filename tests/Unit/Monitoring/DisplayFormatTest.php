<?php

namespace Tests\Unit\Monitoring;

use App\Support\Mcp\DisplayFormat;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DisplayFormatTest extends TestCase
{
    public function test_money_groups_and_rounds_exact_decimals_without_floats(): void
    {
        $this->assertSame('$1,234.50', DisplayFormat::money('1234.5'));
        $this->assertSame('$41,663.40', DisplayFormat::money('41663.400000000000000000'));
        $this->assertSame('$9,007,199,254,740,993.31', DisplayFormat::money('9007199254740993.307')); // beyond float precision
        $this->assertSame('$0.00', DisplayFormat::money('0.004'));
        $this->assertSame('$0.02', DisplayFormat::money('0.025')); // half-even
        $this->assertSame('-$1,000.00', DisplayFormat::money('-1000'));
        $this->assertSame('$0.00', DisplayFormat::money('-0.001'));
        $this->assertSame('$12', DisplayFormat::money('12', 0));
    }

    public function test_missing_money_stays_unknown_and_unexpected_formats_are_shown_verbatim(): void
    {
        $this->assertNull(DisplayFormat::money(null));
        foreach (['1e3', '1,000', 'NaN', ''] as $value) {
            $this->assertSame($value, DisplayFormat::money($value));
        }
    }

    public function test_quantity_trimming_is_lossless(): void
    {
        $this->assertSame('60', DisplayFormat::quantity('60.000000000000000000'));
        $this->assertSame('45.000125', DisplayFormat::quantity('45.000125000000000000'));
        $this->assertSame('2.000000000000000001', DisplayFormat::quantity('2.000000000000000001'));
        $this->assertSame('100', DisplayFormat::quantity('100'));
        $this->assertSame('0', DisplayFormat::quantity('0.000'));
        $this->assertNull(DisplayFormat::quantity(null));
    }

    public function test_share_uses_exact_division_and_refuses_unknown_or_zero_totals(): void
    {
        $this->assertSame('33.3', DisplayFormat::share('1', '3'));
        $this->assertSame('100.0', DisplayFormat::share('5.00', '5'));
        $this->assertNull(DisplayFormat::share('1', '0'));
        $this->assertNull(DisplayFormat::share(null, '10'));
        $this->assertNull(DisplayFormat::share('1', null));
        $this->assertNull(DisplayFormat::share('-1', '10'));
    }

    public function test_tones_treat_unknown_and_stale_states_as_warnings(): void
    {
        $this->assertSame('ok', DisplayFormat::tone('fresh'));
        $this->assertSame('warn', DisplayFormat::tone('stale'));
        $this->assertSame('warn', DisplayFormat::tone('source_time_unknown'));
        $this->assertSame('bad', DisplayFormat::tone('failed'));
        $this->assertSame('bad', DisplayFormat::tone('critical'));
        $this->assertSame('neutral', DisplayFormat::tone('disabled'));
        $this->assertSame('neutral', DisplayFormat::tone(null));
    }

    public function test_time_keeps_the_exact_source_instant_beside_the_local_rendering(): void
    {
        $time = DisplayFormat::time(CarbonImmutable::parse('2026-10-19T13:30:00Z'));
        $this->assertSame('2026-10-19T13:30:00+00:00', $time['iso']);
        $this->assertSame('Oct 19, 08:30:00 CDT', $time['local']);
        $this->assertSame('2026-10-19T13:30:00Z', DisplayFormat::time('2026-10-19T13:30:00Z')['iso']);
        $this->assertSame(['iso' => 'not-a-time', 'local' => 'not-a-time'], DisplayFormat::time('not-a-time'));
        $this->assertNull(DisplayFormat::time(null));
    }
}
