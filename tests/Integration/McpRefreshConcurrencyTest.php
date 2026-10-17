<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\McpOauthCredential;
use App\Models\User;
use Symfony\Component\Process\Process;
use Tests\Support\McpFixture;
use Tests\TestCase;

class McpRefreshConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
    }

    public function test_two_workers_refresh_once_and_a_new_process_reuses_persisted_credentials(): void
    {
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertExitCode(0);
        $user = User::factory()->create();
        $connection = McpFixture::connection($user);
        McpOauthCredential::where('mcp_connection_id', $connection->id)->first()->update(['expires_at' => now()->subMinute()]);
        $log = tempnam(sys_get_temp_dir(), 'mcp-refresh-test-');
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-refresh-worker.php'), (string) $connection->id, $log], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(15);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $this->assertSame(hash('sha256', 'synthetic-access'), $worker->getOutput());
            }
            $this->assertSame("refresh\n", file_get_contents($log));
            // Third process starts with no session/service state: credentials survive restart.
            $workers[0]->run();
            $this->assertTrue($workers[0]->isSuccessful(), $workers[0]->getErrorOutput());
            $this->assertSame("refresh\n", file_get_contents($log));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            McpConnection::whereKey($connection->id)->delete();
            $user->delete();
            unlink($log);
        }
    }
}
