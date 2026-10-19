<?php

namespace Tests\Integration;

use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\IntegrationCleanup;
use Tests\Support\StrategySignalFixture as F;
use Tests\TestCase;

class StrategySignalConcurrencyTest extends TestCase
{
    private ?int $fixture = null;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    protected function tearDown(): void
    {
        IntegrationCleanup::connection($this->fixture);
        parent::tearDown();
    }

    public function test_duplicate_workers_commit_one_complete_cycle_and_database_constrains_outcomes(): void
    {
        [$c] = F::seed();
        $this->fixture = $c->id;
        $workers = [];
        DB::beginTransaction();
        DB::table('mcp_connections')->where('id', $c->id)->lockForUpdate()->first();
        try {
            for ($i = 0; $i < 3; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-strategy-signal-worker.php'), (string) $c->id, now()->toISOString()], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(30);
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
            $this->assertCount(1, array_unique($outputs));
            $this->assertStringContainsString(':signal:BUY', $outputs[0]);
            $this->assertSame(1, StrategyCycle::where('mcp_connection_id', $c->id)->count());
            $row = StrategySignal::where('mcp_connection_id', $c->id)->where('symbol', 'DEMO')->sole();
            $this->assertSame('mcp_trading', DB::selectOne("SELECT schemaname FROM pg_tables WHERE tablename = 'mcp_strategy_signals'")->schemaname);
            $this->expectException(QueryException::class);
            DB::table('mcp_strategy_signals')->where('id', $row->id)->update(['side' => null]);
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
