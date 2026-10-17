<?php

namespace Tests\Integration;

use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Monitoring\Monitor;
use App\Services\Monitoring\VersionLedger;
use App\Services\Operations\BackupCipher;
use App\Services\Operations\Backups;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\McpFixture;
use Tests\Support\MonitoringGateway;
use Tests\TestCase;

class BackupRestoreDrillTest extends TestCase
{
    private ?string $dir = null;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        if (! is_executable(rtrim(config('operations.backup.pg_bin'), '/').'/pg_dump')) {
            $this->markTestSkipped('PostgreSQL client binaries unavailable.');
        }
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertExitCode(0);
        $this->dir = sys_get_temp_dir().'/mcp-drill-test-'.bin2hex(random_bytes(6));
        config(['operations.backup.dir' => $this->dir, 'operations.backup.key_file' => $this->dir.'/keys/backup.key', 'operations.backup.offsite_dir' => null]);
        BackupCipher::createKey($this->dir.'/keys/backup.key');
    }

    protected function tearDown(): void
    {
        if ($this->dir) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    public function test_real_backup_restores_into_a_dropped_scratch_database_with_matching_counts(): void
    {
        $this->app->instance(BrokerGateway::class, new MonitoringGateway);
        $connection = McpFixture::connection();
        app(VersionLedger::class)->policy($connection, $connection->user_id, ['synthetic' => true]);
        app(Monitor::class)->sync($connection->id, 'drill:'.Str::uuid());
        $connection->update(['capability_manifest' => ['synthetic_tool' => ['fingerprint' => 'synthetic']]]);

        $backup = app(Backups::class)->run();
        $this->assertGreaterThan(0, $backup['counts']['mcp_snapshots']);
        foreach (['mcp_oauth_credentials', 'mcp_outbox', 'mcp_component_status', 'mcp_sleeve_allocations'] as $table) {
            $this->assertArrayHasKey($table, $backup['counts']);
        }
        $drill = app(Backups::class)->restoreDrill();
        $this->assertSame([$backup['file'], count($backup['counts']), array_sum($backup['counts']), true, true],
            [$drill['backup'], $drill['tables_verified'], $drill['rows'], $drill['encrypted_columns_readable'], $drill['scratch_database_dropped']]);
        $this->assertSame([], DB::select("SELECT datname FROM pg_database WHERE datname LIKE 'mcp_trading_restore_drill_%'"));
        $this->assertSame('mcp_trading_test', DB::selectOne('SELECT current_database() AS db')->db);

        $manifest = json_decode((string) file_get_contents($this->dir.'/'.$backup['file'].'.json'), true);
        $manifest['sha256'] = str_repeat('0', 64);
        file_put_contents($this->dir.'/'.$backup['file'].'.json', json_encode($manifest));
        $this->expectException(RuntimeException::class);
        app(Backups::class)->restoreDrill();
    }

    public function test_unreadable_restored_credentials_fail_the_drill_and_still_drop_the_scratch_database(): void
    {
        $connection = McpFixture::connection();
        DB::table('mcp_oauth_credentials')->where('mcp_connection_id', $connection->id)->update(['access_token' => 'invalid-encrypted-fixture']);
        app(Backups::class)->run();
        try {
            app(Backups::class)->restoreDrill();
            $this->fail('Unreadable OAuth credentials were accepted.');
        } catch (DecryptException) {
            $this->assertSame([], DB::select("SELECT datname FROM pg_database WHERE datname LIKE 'mcp_trading_restore_drill_%'"));
            $this->assertSame([], glob(sys_get_temp_dir().'/mcp_trading_restore_drill_*.dump') ?: []);
        } finally {
            DB::table('mcp_oauth_credentials')->where('mcp_connection_id', $connection->id)->delete();
        }
    }
}
