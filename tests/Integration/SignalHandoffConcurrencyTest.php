<?php

namespace Tests\Integration;

use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SignalOrderHandoff;
use App\Models\PaperTrading\SimulationOrder;
use App\Services\PaperTrading\StrategySignals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\IntegrationCleanup;
use Tests\Support\SignalExecutionFixture as F;
use Tests\TestCase;

class SignalHandoffConcurrencyTest extends TestCase
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

    public function test_duplicate_handoff_workers_create_one_order_and_one_cash_reservation(): void
    {
        [$c] = F::seed();
        $this->fixture = $c->id;
        $signal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        $workers = [];
        DB::beginTransaction();
        DB::table('mcp_connections')->where('id', $c->id)->lockForUpdate()->first();
        try {
            for ($i = 0; $i < 3; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-signal-handoff-worker.php'), (string) $c->id, (string) $signal->id, now()->toISOString()], base_path(),
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
            $this->assertStringContainsString(':order_created:', $outputs[0]);
            $this->assertSame(1, SignalOrderHandoff::where('paper_portfolio_id', $signal->paper_portfolio_id)->count());
            $order = SimulationOrder::where('paper_portfolio_id', $signal->paper_portfolio_id)->sole();
            $this->assertSame($order->reserved_cash, Portfolio::findOrFail($signal->paper_portfolio_id)->reserved_cash);
            $this->assertSame('19.000000000000000000', $order->quantity);
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
