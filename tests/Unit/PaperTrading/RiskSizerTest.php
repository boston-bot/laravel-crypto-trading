<?php

namespace Tests\Unit\PaperTrading;

use App\Services\PaperTrading\RiskSizer;
use App\Services\PaperTrading\SignalExecutionPolicies;
use App\Services\PaperTrading\TradingCosts;
use App\Support\Mcp\Decimal;
use PHPUnit\Framework\TestCase;

class RiskSizerTest extends TestCase
{
    private function plannedSize(string $equity, string $cash, string $price, string $stop): array
    {
        return (new RiskSizer(new TradingCosts))->calculate(Decimal::from($equity), Decimal::from($cash), Decimal::from($price), Decimal::from($stop),
            SignalExecutionPolicies::DEFAULTS + ['cost_schedule' => TradingCosts::reviewed()]);
    }

    public function test_risk_position_and_cash_caps_round_down_and_zero_size_is_explicit(): void
    {
        $this->assertSame('4', $this->plannedSize('10000', '10000', '100', '80')['quantity']); // costs included in planned risk
        $this->assertSame('20', $this->plannedSize('10000', '10000', '100', '99')['quantity']);
        $this->assertSame('1', $this->plannedSize('10000', '200', '100', '99')['quantity']); // reserve partial-execution costs too
        $this->assertSame('0', $this->plannedSize('500', '500', '101', '99')['quantity']);
        $this->assertSame('whole_share_size_zero', $this->plannedSize('500', '500', '101', '99')['reason']);
        $this->assertSame('no_positive_risk_or_cash', $this->plannedSize('10000', '0', '100', '99')['reason']);
    }

    public function test_fractional_market_sizing_rounds_down_to_six_decimals_with_the_broker_minimum(): void
    {
        $size = fn (string $equity, string $price, string $stop, int $scale = 6) => (new RiskSizer(new TradingCosts))->calculate(Decimal::from($equity), Decimal::from($equity),
            Decimal::from($price), Decimal::from($stop), SignalExecutionPolicies::DEFAULTS + ['cost_schedule' => TradingCosts::reviewed()], null, $scale);
        // $500 cannot buy one $101 share, but the 20% position cap buys 100 / 101 = 0.990099 shares (rounded down).
        $this->assertSame(['0.990099', null, 6], [$size('500', '101', '99')['quantity'], $size('500', '101', '99')['reason'], $size('500', '101', '99')['quantity_scale']]);
        $this->assertSame(['0', 'whole_share_size_zero'], [$size('500', '101', '99', 0)['quantity'], $size('500', '101', '99', 0)['reason']]);
        $this->assertArrayNotHasKey('quantity_scale', $size('500', '101', '99', 0));
        // A position below the $1.00 minimum order value is skipped, not rounded up.
        $this->assertSame(['0', 'below_fractional_minimum_order_value'], [$size('4', '101', '99')['quantity'], $size('4', '101', '99')['reason']]);
        $this->assertSame('below_fractional_minimum_order_value', $size('5', '101', '99')['reason']); // $1.00 cap buys 0.009900 shares = $0.9999
        $this->assertSame('0.010000', $size('5.05', '101', '99')['quantity']);                           // $1.01 cap buys 0.010000 shares = $1.01
        $this->expectException(\InvalidArgumentException::class);
        $size('500', '101', '99', 7);
    }
}
