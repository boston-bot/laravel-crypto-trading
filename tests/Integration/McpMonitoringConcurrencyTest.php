<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\Monitoring\Holding;
use App\Models\Monitoring\Outbox;
use App\Models\Monitoring\Run;
use App\Models\Monitoring\Snapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\McpFixture;
use Tests\TestCase;

class McpMonitoringConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
    }

    public function test_two_workers_publish_one_snapshot_and_restart_does_not_repeat_reads(): void
    {
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertExitCode(0);
        $connection = McpFixture::connection();
        $log = tempnam(sys_get_temp_dir(), 'mcp-monitor-test-');
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-monitor-worker.php'), (string) $connection->id, $log], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(15);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            $this->assertSame($workers[0]->getOutput(), $workers[1]->getOutput());
            $run = Run::where('mcp_connection_id', $connection->id)->sole();
            $this->assertSame('complete', $run->status);
            $snapshot = Snapshot::where('run_id', $run->id)->sole();
            $this->assertSame('complete', $snapshot->status);
            $holding = Holding::where('snapshot_id', $snapshot->id)->sole();
            $this->assertSame('2.000000000000000001', $holding->quantity);
            $this->assertSame("portfolio\npositions\norders\nquotes\n", file_get_contents($log));
            $workers[0]->run();
            $this->assertTrue($workers[0]->isSuccessful(), $workers[0]->getErrorOutput());
            $this->assertSame($run->id, $workers[0]->getOutput());
            $this->assertSame("portfolio\npositions\norders\nquotes\n", file_get_contents($log));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            // Fixture-only cleanup; no runtime database can pass the test guard.
            $incidentIds = DB::table('mcp_incidents')->where('mcp_connection_id', $connection->id)->pluck('id')->all();
            foreach (Outbox::where('kind', 'notification')->get() as $event) {
                if (in_array($event->payload['incident_id'], $incidentIds, true)) {
                    $event->delete();
                }
            }
            $snapshotIds = Snapshot::where('mcp_connection_id', $connection->id)->pluck('id');
            Holding::whereIn('snapshot_id', $snapshotIds)->delete();
            Snapshot::where('mcp_connection_id', $connection->id)->delete();
            foreach (['mcp_runs', 'mcp_incidents', 'mcp_policy_versions'] as $table) {
                DB::table($table)->where('mcp_connection_id', $connection->id)->delete();
            }
            McpConnection::whereKey($connection->id)->delete();
            User::whereKey($connection->user_id)->delete();
            unlink($log);
        }
    }
}
