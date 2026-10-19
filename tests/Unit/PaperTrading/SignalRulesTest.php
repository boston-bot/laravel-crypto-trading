<?php

namespace Tests\Unit\PaperTrading;

use App\Services\PaperTrading\SignalParameters;
use App\Services\PaperTrading\SignalRules;
use PHPUnit\Framework\TestCase;

class SignalRulesTest extends TestCase
{
    private function entry(string $ask, string $setup = 'breakout'): array
    {
        return (new SignalRules)->entry(['setup' => $setup, 'levels' => ['trigger' => '100'], 'indicators' => ['atr' => '2']], $ask, SignalParameters::current()['values']);
    }

    public function test_entry_requires_strict_trigger_crossing_and_inclusive_chase_bound_with_exact_decimals(): void
    {
        $this->assertSame('trigger_not_crossed', $this->entry('100')['reason']);
        $this->assertSame('BUY', $this->entry('100.000000000000000001')['side']);
        $this->assertSame('BUY', $this->entry('101')['side']);
        $this->assertSame('maximum_chase_exceeded', $this->entry('101.000000000000000001')['reason']);
        $this->assertSame('pullback_trigger_crossed', $this->entry('100.01', 'pullback')['reason']);
        $this->assertSame('96.0100', $this->entry('100.01')['facts']['indicative_initial_stop']);
        $this->assertSame('wait', $this->entry('100.01', 'none')['status']);
    }

    private function exit(array $changes = [], ?array $profile = ['indicators' => ['close' => '105', 'sma_fast' => '101']], string $bid = '105', string $earnings = 'pass'): array
    {
        return (new SignalRules)->exit($bid, $changes + ['initial_stop' => '96', 'trailing_stop' => '102', 'sessions_held' => 2, 'progressed' => false],
            $profile, ['status' => $earnings], SignalParameters::current()['values']);
    }

    public function test_exit_priority_boundaries_and_unknown_data_are_explicit(): void
    {
        $this->assertSame('initial_stop_hit', $this->exit(bid: '96', earnings: 'blocked')['reason']);
        $this->assertSame('trailing_stop_hit', $this->exit(bid: '102')['reason']);
        $this->assertSame('holding_exit_not_triggered', $this->exit(bid: '102.000000000000000001')['reason']);
        $this->assertSame('daily_close_below_fast_sma', $this->exit(profile: ['indicators' => ['close' => '99', 'sma_fast' => '100']])['reason']);
        $this->assertSame('time_exit_without_progress', $this->exit(['sessions_held' => 20])['reason']);
        $this->assertSame('holding_exit_not_triggered', $this->exit(['sessions_held' => 20, 'progressed' => true])['reason']);
        $this->assertSame('last_session_before_earnings', $this->exit(earnings: 'blocked')['reason']);
        $this->assertSame('exit_reference_data_unavailable', $this->exit(profile: null, earnings: 'unavailable')['reason']);
        $this->assertSame('initial_stop_hit', $this->exit(profile: null, bid: '90', earnings: 'unavailable')['reason']);
    }
}
