<?php

namespace Tests\Unit\PaperTrading;

use App\Services\PaperTrading\PaperEquityChart;
use PHPUnit\Framework\TestCase;

class PaperEquityChartTest extends TestCase
{
    public function test_missing_sessions_break_the_line_instead_of_becoming_zero_or_interpolation(): void
    {
        $chart = (new PaperEquityChart)->build([
            ['date' => '2026-10-19', 'equity' => '500.000000000000000001'],
            ['date' => '2026-10-20', 'equity' => null],
            ['date' => '2026-10-21', 'equity' => '501.000000000000000001'],
        ]);
        $this->assertSame(2, $chart['known']);
        $this->assertSame(1, $chart['missing']);
        $this->assertCount(2, $chart['segments']);
        $this->assertSame('500.000000000000000001', $chart['min']);
        $this->assertSame('501.000000000000000001', $chart['max']);
        $this->assertSame('195.00', $chart['dots'][0]['y']);
        $this->assertSame('25.00', $chart['dots'][1]['y']);
    }

    public function test_empty_and_constant_series_have_no_division_by_zero_or_invented_points(): void
    {
        $chart = (new PaperEquityChart)->build([['date' => '2026-10-19', 'equity' => null]]);
        $this->assertSame([], $chart['dots']);
        $this->assertNull($chart['min']);
        $flat = (new PaperEquityChart)->build([['date' => '2026-10-19', 'equity' => '500'], ['date' => '2026-10-20', 'equity' => '500']]);
        $this->assertSame(['110', '110'], array_column($flat['dots'], 'y'));
        $this->assertCount(1, $flat['segments']);
    }
}
