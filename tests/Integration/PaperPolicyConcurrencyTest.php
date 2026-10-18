<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\PolicyApplication;
use App\Models\PaperTrading\Portfolio;
use App\Services\PaperTrading\PaperMandates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\McpFixture;
use Tests\TestCase;

class PaperPolicyConcurrencyTest extends TestCase
{
    public function test_two_initial_applications_create_one_virtual_opening_balance(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertExitCode(0);
        $connection = McpFixture::connection();
        $draft = app(PaperMandates::class)->append($connection, $connection->user_id, 0, (string) Str::uuid(), [
            'initial_cash' => '10000.00', 'long_term_pct' => '40', 'active_pct' => '50', 'cash_pct' => '10',
            'max_position_pct' => '20', 'daily_loss_pause_pct' => '2', 'monthly_budget' => '100.00',
            'scan_seconds' => 60, 'decision_minutes' => 5, 'news_minutes' => 5,
        ], 'Synthetic concurrent application.');
        $workers = [];
        DB::beginTransaction();
        McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ([Str::uuid(), Str::uuid()] as $requestId) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-paper-policy-worker.php'), (string) $connection->id, (string) $draft->id, (string) $requestId], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            DB::commit();
            $outputs = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $outputs[] = $worker->getOutput();
            }
            sort($outputs);
            $this->assertSame(['applied', 'conflict'], $outputs);
            $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->sole();
            $this->assertSame(1, PolicyApplication::where('paper_portfolio_id', $portfolio->id)->count());
            $this->assertSame(1, CashEntry::where('paper_portfolio_id', $portfolio->id)->count());
            $this->assertSame('10000.000000000000000000', $portfolio->cash);
            $this->assertSame(1, $portfolio->ledger_version);
            $this->assertSame(1, $portfolio->policy_revision);
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
