<?php

namespace Tests\Integration;

use App\Models\PaperTrading\DecisionRun;
use App\Models\Research\Budget;
use App\Services\PaperTrading\DecisionScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\PaperDecisionFixture;
use Tests\TestCase;

class PaperDecisionConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertExitCode(0);
        Budget::query()->delete();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        Queue::fake();
    }

    private function race(array $ids, array $config = []): string
    {
        $log = tempnam(sys_get_temp_dir(), 'paper-decision-race-');
        $workers = [];
        try {
            foreach ($ids as $id) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-paper-decision-worker.php'), $id, $log, now()->utc()->format(DATE_ATOM), json_encode($config)], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }

            return (string) file_get_contents($log);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            @unlink($log);
        }
    }

    public function test_duplicate_workers_make_one_call_and_settle_one_reservation(): void
    {
        [$connection] = PaperDecisionFixture::seed();
        app(DecisionScheduler::class)->tick();
        $run = DecisionRun::where('mcp_connection_id', $connection->id)->sole();
        $this->assertSame("call\n", $this->race([$run->id, $run->id]));
        $this->assertSame('wait', $run->fresh()->status);
        $this->assertNull($run->fresh()->active_portfolio_id);
        $this->assertSame('0.000000000000000000', Budget::sole()->reserved);
        $this->assertSame('0.001800000000000000', Budget::sole()->actual);
    }

    public function test_different_portfolios_cannot_concurrently_overreserve_the_shared_budget(): void
    {
        [$first, $firstUser] = PaperDecisionFixture::seed();
        [$second, $secondUser] = PaperDecisionFixture::seed();
        config(['mcp.operator_emails' => [$firstUser->email, $secondUser->email]]);
        app(DecisionScheduler::class)->tick();
        $ids = DecisionRun::whereIn('mcp_connection_id', [$first->id, $second->id])->pluck('id')->all();
        $this->assertSame(2, count($ids));
        $config = ['research.fixed_monthly_costs' => '99.997', 'research.input_cost_per_million' => '0', 'research.output_cost_per_million' => '1', 'research.reduce_at' => '1.00'];
        $this->assertSame("call\n", $this->race($ids, $config));
        $this->assertSame(1, DecisionRun::whereIn('id', $ids)->where('status', 'wait')->count());
        $this->assertSame(1, DecisionRun::whereIn('id', $ids)->where('reason', 'budget_exhausted')->count());
        $this->assertSame('0.000000000000000000', Budget::sole()->reserved);
    }
}
