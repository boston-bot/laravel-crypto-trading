<?php

namespace Tests\Unit\Mcp;

use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\RobinhoodAccountNormalizer;
use PHPUnit\Framework\TestCase;

class RobinhoodAccountNormalizerTest extends TestCase
{
    public function test_discovery_retains_broker_flags_without_inventing_account_classification(): void
    {
        $row = ['account_number' => 'synthetic-account', 'nickname' => 'Synthetic Agent', 'type' => 'unreviewed',
            'brokerage_account_type' => 'individual', 'management_type' => 'unknown', 'agentic_allowed' => true,
            'state' => 'active', 'deactivated' => false, 'permanently_deactivated' => false];
        $record = BrokerRecord::from(Operation::Accounts, RobinhoodAccountNormalizer::fields($row), null);
        $this->assertSame('unknown', $record->fields['account_kind']);
        $this->assertTrue($record->fields['agentic_allowed']);
        $this->assertSame('Synthetic Agent', $record->fields['nickname']);
    }

    public function test_agentic_monitoring_classification_requires_current_broker_flags(): void
    {
        $row = ['account_number' => 'synthetic-account', 'type' => 'limited_margin', 'management_type' => 'self_directed',
            'brokerage_account_type' => 'individual', 'agentic_allowed' => true, 'state' => 'active',
            'deactivated' => false, 'permanently_deactivated' => false];
        $this->assertSame('agentic', RobinhoodAccountNormalizer::fields($row)['account_kind']);
        foreach ([['agentic_allowed' => false], ['deactivated' => true], ['permanently_deactivated' => true], ['state' => 'pending'], ['management_type' => 'unknown'], ['agentic_allowed' => 'true']] as $override) {
            $this->assertSame('unknown', RobinhoodAccountNormalizer::fields(array_replace($row, $override))['account_kind']);
        }
    }

    public function test_missing_eligibility_flag_is_not_assumed_false_or_true(): void
    {
        $this->expectException(ReadFailure::class);
        BrokerRecord::from(Operation::Accounts, RobinhoodAccountNormalizer::fields(['account_number' => 'synthetic-account']), null);
    }
}
