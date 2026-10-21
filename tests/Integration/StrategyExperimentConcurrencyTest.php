<?php

namespace Tests\Integration;

use App\Models\PaperTrading\StrategyExperiment;
use App\Models\PaperTrading\StrategyExperimentStep as Step;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\ExperimentFixture as F;
use Tests\Support\IntegrationCleanup;
use Tests\TestCase;

class StrategyExperimentConcurrencyTest extends TestCase
{
    private ?int $fixture = null;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-21T14:00:00Z'));
        F::enable();
    }

    protected function tearDown(): void
    {
        IntegrationCleanup::connection($this->fixture);
        parent::tearDown();
    }

    private function race(string $mode, string $argument): array
    {
        $workers = [];
        try {
            for ($i = 0; $i < 3; $i++) {
                $p = new Process([PHP_BINARY, base_path('tests/Support/mcp-experiment-worker.php'), (string) $this->fixture, $mode, $argument], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $p->setTimeout(30);
                $p->start();
                $workers[] = $p;
            }
            $outputs = [];
            foreach ($workers as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $outputs[] = trim($p->getOutput());
            }

            return $outputs;
        } finally {
            foreach ($workers as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
        }
    }

    public function test_duplicate_starts_enqueue_once_and_competing_workers_commit_only_one_session(): void
    {
        [$c] = E::seed();
        $this->fixture = $c->id;
        $ids = $this->race('start', (string) Str::uuid());
        $this->assertCount(1, array_unique($ids));
        $row = StrategyExperiment::where('mcp_connection_id', $c->id)->sole();
        $this->assertSame(1, DB::table('jobs')->where('queue', 'experiments')->count());
        $out = $this->race('work', (string) $row->id);
        $this->assertSame(1, count(array_filter($out, fn ($value) => $value === '0')));
        $this->assertSame(1, Step::where('experiment_id', $row->id)->count());
        $this->assertSame(2, DB::table('jobs')->where('queue', 'experiments')->count());
        $this->assertNull($row->fresh()->lease_token);
        $this->assertSame('mcp_trading', DB::selectOne("SELECT schemaname FROM pg_tables WHERE tablename = 'mcp_strategy_experiments'")->schemaname);
        $this->expectException(QueryException::class);
        DB::table('mcp_strategy_experiment_steps')->where('experiment_id', $row->id)->update(['completed_key' => null]);
    }
}
