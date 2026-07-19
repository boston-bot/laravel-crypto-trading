<?php

namespace Tests\Feature\Trading;

use App\Jobs\SnapshotPaperPortfolioJob;
use App\Models\BrokerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotPaperPortfolioJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_creates_snapshots_for_selected_broker_in_paper_mode(): void
    {
        config()->set('broker.mode', 'paper');

        $coinbaseAccount = BrokerAccount::query()->create([
            'broker' => 'coinbase',
            'external_account_id' => 'cb-main',
            'currency' => 'USD',
            'buying_power' => 10000,
            'cash_balance' => 10000,
            'equity' => 10000,
            'status' => 'active',
            'snapshot_at' => now(),
        ]);

        BrokerAccount::query()->create([
            'broker' => 'robinhood',
            'external_account_id' => 'rh-main',
            'currency' => 'USD',
            'buying_power' => 5000,
            'cash_balance' => 5000,
            'equity' => 5000,
            'status' => 'active',
            'snapshot_at' => now(),
        ]);

        app()->call([new SnapshotPaperPortfolioJob('coinbase'), 'handle']);

        $this->assertDatabaseCount('paper_portfolio_snapshots', 1);
        $this->assertDatabaseHas('paper_portfolio_snapshots', [
            'broker_account_id' => $coinbaseAccount->id,
        ]);
    }

    public function test_job_skips_snapshotting_when_not_in_paper_mode(): void
    {
        config()->set('broker.mode', 'live');

        BrokerAccount::query()->create([
            'broker' => 'coinbase',
            'external_account_id' => 'cb-main',
            'currency' => 'USD',
            'buying_power' => 10000,
            'cash_balance' => 10000,
            'equity' => 10000,
            'status' => 'active',
            'snapshot_at' => now(),
        ]);

        app()->call([new SnapshotPaperPortfolioJob('coinbase'), 'handle']);

        $this->assertDatabaseCount('paper_portfolio_snapshots', 0);
    }
}
