<?php

namespace Tests\Integration;

use App\Models\PaperTrading\SessionValuation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\IntegrationCleanup;
use Tests\TestCase;

class SessionValuationConcurrencyTest extends TestCase
{
    private ?int $fixture = null;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T13:00:00Z'));
    }

    protected function tearDown(): void
    {
        IntegrationCleanup::connection($this->fixture);
        parent::tearDown();
    }

    public function test_racing_capture_workers_commit_one_fixed_opening_valuation(): void
    {
        [$c, $p] = E::seed();
        $this->fixture = $c->id;
        $workers = [];
        DB::beginTransaction();
        DB::table('mcp_connections')->where('id', $c->id)->lockForUpdate()->first();
        try {
            for ($i = 0; $i < 3; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-session-valuation-worker.php'), (string) $c->id, '2026-10-19T14:00:00Z'], base_path(),
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
            $this->assertStringStartsWith('complete:', $outputs[0]);
            $valuation = SessionValuation::where('paper_portfolio_id', $p->id)->sole();
            $this->assertSame('10000.000000000000000000', $valuation->equity);
            $this->assertSame('mcp_trading', DB::selectOne('SELECT current_schema() AS schema')->schema);
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
