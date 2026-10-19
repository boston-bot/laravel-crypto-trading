<?php

namespace Tests\Unit\PaperTrading;

use App\Services\PaperTrading\EligibilityRules;
use App\Services\PaperTrading\RuleEligibilityPolicies;
use PHPUnit\Framework\TestCase;

class EligibilityRulesTest extends TestCase
{
    public function test_decimal_thresholds_include_exact_boundaries_without_rounding(): void
    {
        $rules = new EligibilityRules;
        $history = ['verified' => true, 'sessions' => 252, 'average_dollar_volume_20' => '10000000'];
        $result = $rules->thresholds(['bid' => '199.5', 'ask' => '200.5'], $history, RuleEligibilityPolicies::DEFAULTS);
        $this->assertSame(['pass', 'pass', 'pass', 'pass'], array_column($result, 'status'));
        $above = $rules->thresholds(['bid' => '199.5', 'ask' => '200.500000000000000001'], $history, RuleEligibilityPolicies::DEFAULTS);
        $this->assertSame('ineligible', $above['spread']['status']);
        $low = $rules->thresholds(['bid' => '4.999999999999999999', 'ask' => '5'],
            ['verified' => true, 'sessions' => 251, 'average_dollar_volume_20' => '9999999.999999999999999999'], RuleEligibilityPolicies::DEFAULTS);
        $this->assertSame(['ineligible', 'pass', 'ineligible', 'ineligible'], array_column($low, 'status'));
    }

    public function test_unknown_unverified_and_binary_float_facts_cannot_pass(): void
    {
        $rules = new EligibilityRules;
        $this->assertSame(['unavailable', 'unavailable', 'unavailable', 'unavailable'], array_column($rules->thresholds(null, null, RuleEligibilityPolicies::DEFAULTS), 'status'));
        $result = $rules->thresholds(['bid' => 5.0, 'ask' => '5'], ['verified' => false, 'sessions' => 252, 'average_dollar_volume_20' => '10000000'], RuleEligibilityPolicies::DEFAULTS);
        $this->assertSame(['unavailable', 'unavailable', 'unavailable', 'unavailable'], array_column($result, 'status'));
        $result = $rules->thresholds(['bid' => '10', 'ask' => '9'], ['verified' => true, 'sessions' => 252, 'average_dollar_volume_20' => 10000000.0], RuleEligibilityPolicies::DEFAULTS);
        $this->assertSame('unavailable', $result['spread']['status']);
        $this->assertSame('unavailable', $result['liquidity']['status']);
    }
}
