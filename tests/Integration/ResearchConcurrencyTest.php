<?php

namespace Tests\Integration;

use App\Models\Research\Budget;
use App\Models\Research\Event;
use App\Models\Research\Run;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Monitoring\Monitor;
use App\Services\Research\ResearchEvents;
use App\Support\Mcp\Decimal;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\McpFixture;
use Tests\Support\MonitoringGateway;
use Tests\Support\ResearchFixture;
use Tests\TestCase;

class ResearchConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertExitCode(0);
        // Worker processes need committed rows, so the guarded fixture's global spending ledger is reset per test.
        Budget::query()->delete();
    }

    /** @return array{0: list<string>, 1: string} */
    private function race(array $eventIds, array $config): array
    {
        $log = tempnam(sys_get_temp_dir(), 'mcp-research-test-');
        $workers = [];
        try {
            foreach ($eventIds as $id) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-research-worker.php'), (string) $id, $log, json_encode($config)], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            $outputs = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $outputs[] = $worker->getOutput();
            }
            sort($outputs);

            return [$outputs, (string) file_get_contents($log)];
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            @unlink($log);
        }
    }

    private function seedAccount(): array
    {
        $this->app->instance(BrokerGateway::class, new MonitoringGateway);
        $connection = McpFixture::connection();
        $user = User::findOrFail($connection->user_id);
        app(Monitor::class)->sync($connection->id, 'research-seed:'.Str::uuid());

        return [$connection, $user];
    }

    public function test_two_workers_on_one_subject_make_exactly_one_model_call(): void
    {
        [$connection, $user] = $this->seedAccount();
        $events = app(ResearchEvents::class);
        $first = $events->addEvidence($connection, $user->id, ResearchFixture::evidence());
        $second = $events->request($connection, $user->id, 'SYNTH', (string) Str::uuid());
        [$outputs, $log] = $this->race([$first->id, $second->id], []);
        $this->assertSame(['none', 'succeeded'], $outputs);
        $this->assertSame("call\n", $log);
        $this->assertSame(1, Run::where('mcp_connection_id', $connection->id)->count());
        $this->assertSame('subject_busy', Event::whereKey([$first->id, $second->id])->where('status', 'pending')->sole()->status_reason);
        $this->assertSame(0, Run::where('mcp_connection_id', $connection->id)->whereNotNull('active_subject')->count());
    }

    public function test_concurrent_reservations_cannot_exceed_the_envelope(): void
    {
        [$connection, $user] = $this->seedAccount();
        $events = app(ResearchEvents::class);
        $events->watch($connection, $user->id, 'WATCH', null);
        $a = $events->request($connection, $user->id, 'SYNTH', (string) Str::uuid());
        $b = $events->request($connection, $user->id, 'WATCH', (string) Str::uuid());
        // Input priced at zero: each call reserves exactly 2000 output tokens at $1/M = $0.002, and only $0.003 remains.
        $config = ['research.input_cost_per_million' => '0', 'research.output_cost_per_million' => '1.00', 'research.fixed_monthly_costs' => '99.997'];
        [$outputs, $log] = $this->race([$a->id, $b->id], $config);
        $this->assertSame(['budget_exhausted', 'succeeded'], $outputs);
        $this->assertSame("call\n", $log);
        $budget = Budget::where('period', now()->utc()->format('Y-m'))->sole();
        $this->assertSame('0.000000000000000000', $budget->reserved);
        $this->assertSame('0.002000000000000000', $budget->actual);
        $this->assertSame(-1, Decimal::from('99.997')->plus(Decimal::from($budget->actual))->compareTo(Decimal::from('100')));
    }
}
