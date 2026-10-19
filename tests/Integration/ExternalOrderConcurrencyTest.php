<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\Monitoring\ExternalOrder;
use App\Models\Monitoring\ExternalOrderReview;
use App\Models\Monitoring\Holding;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Outbox;
use App\Models\Monitoring\Snapshot;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Monitoring\ExternalOrders;
use App\Services\Monitoring\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ExternalOrderGateway;
use Tests\Support\McpFixture;
use Tests\TestCase;

class ExternalOrderConcurrencyTest extends TestCase
{
    private ?McpConnection $fixture = null;

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
        if ($connection = $this->fixture) {
            // Only fixture IDs in the guarded test database; never operator records.
            $orders = ExternalOrder::where('mcp_connection_id', $connection->id)->pluck('id');
            DB::table('mcp_external_order_reviews')->whereIn('external_order_id', $orders)->delete();
            DB::table('mcp_external_orders')->whereIn('id', $orders)->delete();
            DB::table('mcp_broker_order_reads')->where('mcp_connection_id', $connection->id)->delete();
            $incidents = Incident::where('mcp_connection_id', $connection->id)->pluck('id')->all();
            foreach (Outbox::where('kind', 'notification')->get() as $event) {
                if (in_array($event->payload['incident_id'], $incidents, true)) {
                    $event->delete();
                }
            }
            Holding::whereIn('snapshot_id', Snapshot::where('mcp_connection_id', $connection->id)->select('id'))->delete();
            foreach (['mcp_snapshots', 'mcp_runs', 'mcp_incidents', 'mcp_policy_versions'] as $table) {
                DB::table($table)->where('mcp_connection_id', $connection->id)->delete();
            }
            McpConnection::whereKey($connection->id)->delete();
            User::whereKey($connection->user_id)->delete();
        }
        parent::tearDown();
    }

    private function race(array $actions): array
    {
        $workers = [];
        DB::beginTransaction();
        McpConnection::whereKey($this->fixture->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($actions as [$mode, $id, $request]) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-external-order-worker.php'), (string) $this->fixture->id,
                    $mode, (string) $id, $request, now()->toIso8601String()], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            DB::commit();
            $outputs = [];
            foreach ($workers as $index => $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                if ($actions[$index][0] === 'record') {
                    $this->assertSame('recorded', $worker->getOutput());
                } else {
                    $this->assertMatchesRegularExpression('/^\d+$/D', $worker->getOutput());
                }
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

    public function test_duplicate_detection_and_reviews_are_atomic_and_a_concurrent_new_order_cannot_be_cleared_by_old_review(): void
    {
        $this->fixture = McpFixture::connection()->fresh();
        config(['mcp.operator_emails' => [User::findOrFail($this->fixture->user_id)->email], 'mcp.external_order_detection_enabled' => false]);
        $this->app->instance(BrokerGateway::class, new ExternalOrderGateway);
        $run = app(Monitor::class)->sync($this->fixture->id, 'external-race-one');
        $snapshot = Snapshot::where('run_id', $run->id)->sole();
        $request = (string) Str::uuid();
        $this->race(array_fill(0, 3, ['record', $snapshot->id, $request]));
        $first = ExternalOrder::where('mcp_connection_id', $this->fixture->id)->sole();
        $this->assertSame(1, DB::table('mcp_broker_order_reads')->where('mcp_connection_id', $this->fixture->id)->count());
        $secondRun = app(Monitor::class)->sync($this->fixture->id, 'external-race-two');
        $secondSnapshot = Snapshot::where('run_id', $secondRun->id)->sole();
        $this->race([['record', $secondSnapshot->id, (string) Str::uuid()], ['review', $first->id, (string) Str::uuid()]]);
        $this->assertSame(1, app(ExternalOrders::class)->pending($this->fixture)->count());
        $this->assertSame('open', Incident::where('mcp_connection_id', $this->fixture->id)->where('incident_key', ExternalOrders::INCIDENT)->sole()->state);
        $second = app(ExternalOrders::class)->pending($this->fixture)->sole();
        $reviewRequest = (string) Str::uuid();
        $outputs = $this->race(array_fill(0, 3, ['review', $second->id, $reviewRequest]));
        $this->assertCount(1, array_unique($outputs));
        $this->assertSame(2, ExternalOrderReview::whereIn('external_order_id', [$first->id, $second->id])->count());
        $this->assertSame(0, app(ExternalOrders::class)->pending($this->fixture)->count());
        $this->assertSame('resolved', Incident::where('mcp_connection_id', $this->fixture->id)->where('incident_key', ExternalOrders::INCIDENT)->sole()->state);
        $this->assertSame('mcp_trading', DB::selectOne("SELECT schemaname FROM pg_tables WHERE tablename = 'mcp_external_orders'")->schemaname);
    }
}
