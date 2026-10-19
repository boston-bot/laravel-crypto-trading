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
}
