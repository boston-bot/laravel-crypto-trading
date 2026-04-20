<?php

namespace Tests\Feature\Trading;

use App\Services\Broker\Robinhood\RobinhoodMapper;
use Tests\TestCase;

class RobinhoodMapperAccountFallbackTest extends TestCase
{
    public function test_account_mapping_falls_back_to_buying_power_when_equity_is_missing(): void
    {
        $payload = [
            'account_number' => 'acct-123',
            'status' => 'active',
            'buying_power' => '22.9000',
            'buying_power_currency' => 'USD',
        ];

        $mapped = app(RobinhoodMapper::class)->mapAccount($payload);

        $this->assertSame(22.9, $mapped['buying_power']);
        $this->assertSame(22.9, $mapped['cash_balance']);
        $this->assertSame(22.9, $mapped['equity']);
    }
}
