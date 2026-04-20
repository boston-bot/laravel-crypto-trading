<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\Position;
use App\Services\Risk\ExposureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExposureServiceDustFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_position_count_ignores_zero_value_dust_positions(): void
    {
        $account = BrokerAccount::query()->create([
            'broker' => 'robinhood',
            'external_account_id' => 'acct-1',
            'currency' => 'USD',
            'buying_power' => 100,
            'cash_balance' => 100,
            'equity' => 100,
            'status' => 'active',
            'snapshot_at' => now(),
        ]);

        $asset = Asset::query()->create([
            'broker' => 'robinhood',
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        Position::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'quantity' => 0.00013023,
            'avg_cost' => 0,
            'market_value' => 0,
            'unrealized_pnl' => 0,
            'snapshot_at' => now(),
        ]);

        $count = app(ExposureService::class)->openPositionCount($account);

        $this->assertSame(0, $count);
    }
}
