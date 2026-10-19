<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\PaperTrading\EligibilityAssessment;
use App\Models\PaperTrading\EligibilityPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ExecutionFixture as E;
use Tests\TestCase;

class RuleEligibilityConcurrencyTest extends TestCase
{
    private ?McpConnection $fixture = null;

    private ?int $portfolioId = null;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertExitCode(0);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    protected function tearDown(): void
    {
        if ($this->fixture) {
            DB::table('mcp_eligibility_assessments')->where('mcp_connection_id', $this->fixture->id)->delete();
            foreach (['mcp_eligibility_policies', 'mcp_paper_execution_versions', 'mcp_paper_cash_entries', 'mcp_paper_policy_applications'] as $table) {
                DB::table($table)->where('paper_portfolio_id', $this->portfolioId)->delete();
            }
            DB::table('mcp_paper_portfolios')->where('id', $this->portfolioId)->delete();
            foreach (['mcp_paper_market_inputs', 'mcp_paper_mandate_versions'] as $table) {
                DB::table($table)->where('mcp_connection_id', $this->fixture->id)->delete();
            }
            McpConnection::whereKey($this->fixture->id)->delete();
            User::whereKey($this->fixture->user_id)->delete();
        }
        parent::tearDown();
    }

    private function race(string $mode, string $request): array
    {
        $workers = [];
        DB::beginTransaction();
        McpConnection::whereKey($this->fixture->id)->lockForUpdate()->firstOrFail();
        try {
            for ($i = 0; $i < 3; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-eligibility-worker.php'), (string) $this->fixture->id, $mode, $request, now()->toISOString()],
                    base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            DB::commit();
            $outputs = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $this->assertMatchesRegularExpression('/^\d+$/D', $worker->getOutput());
                $outputs[] = $worker->getOutput();
            }

            return $outputs;
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

    public function test_duplicate_policy_and_assessment_workers_preserve_one_version_and_one_assessment_with_schema_constraints(): void
    {
        [$this->fixture, $portfolio] = E::seed();
        $this->portfolioId = $portfolio->id;
        $policyIds = $this->race('policy', (string) Str::uuid());
        $this->assertCount(1, array_unique($policyIds));
        $assessmentIds = $this->race('assess', (string) Str::uuid());
        $this->assertCount(1, array_unique($assessmentIds));
        $assessment = EligibilityAssessment::where('mcp_connection_id', $this->fixture->id)->sole();
        $this->assertSame('unavailable', $assessment->status);
        $this->assertSame((string) EligibilityPolicy::where('paper_portfolio_id', $portfolio->id)->sole()->id, $policyIds[0]);
        $this->assertSame('mcp_trading', DB::selectOne("SELECT schemaname FROM pg_tables WHERE tablename = 'mcp_eligibility_assessments'")->schemaname);
        $this->expectException(QueryException::class);
        DB::table('mcp_eligibility_assessments')->where('id', $assessment->id)->update(['status' => 'guessed']);
    }
}
