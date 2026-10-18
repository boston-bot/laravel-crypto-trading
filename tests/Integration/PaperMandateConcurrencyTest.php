<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\PaperTrading\MandateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\McpFixture;
use Tests\TestCase;

class PaperMandateConcurrencyTest extends TestCase
{
    public function test_concurrent_edits_from_the_same_revision_cannot_overwrite_each_other(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertExitCode(0);
        $connection = McpFixture::connection();
        $workers = [];
        DB::beginTransaction();
        McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ([Str::uuid(), Str::uuid()] as $requestId) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-paper-mandate-worker.php'), (string) $connection->id, (string) $requestId], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(20);
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
            sort($outputs);
            $this->assertSame(['conflict', 'saved'], $outputs);
            $this->assertSame(1, MandateVersion::where('mcp_connection_id', $connection->id)->count());
            $this->assertSame(1, MandateVersion::where('mcp_connection_id', $connection->id)->sole()->version);
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
