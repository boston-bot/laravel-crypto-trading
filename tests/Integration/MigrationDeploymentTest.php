<?php

namespace Tests\Integration;

use App\Services\Operations\MigrationDeployment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MigrationDeploymentTest extends TestCase
{
    private ?string $role = null;

    private ?string $dir = null;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertExitCode(0);
        $owner = DB::selectOne('SELECT current_user AS role')->role;
        $this->role = 'mcp_migration_fixture_'.bin2hex(random_bytes(6));
        DB::statement('CREATE ROLE "'.$this->role.'" NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE');
        DB::statement('GRANT USAGE ON SCHEMA "mcp_trading" TO "'.$this->role.'"');
        DB::statement('GRANT SELECT ON "mcp_trading".migrations TO "'.$this->role.'"');
        $this->dir = sys_get_temp_dir().'/mcp-migration-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir.'/owner.json', json_encode(['username' => $owner, 'password' => getenv('TEST_DB_PASSWORD') ?: '']));
        chmod($this->dir.'/owner.json', 0600);
        DB::statement('SET ROLE "'.$this->role.'"');
    }

    protected function tearDown(): void
    {
        if ($this->role) {
            DB::statement('RESET ROLE');
            DB::statement('DROP TABLE IF EXISTS "mcp_trading".migration_deployment_fixture');
            DB::table('migrations')->where('migration', 'like', '2099_01_01_000001_deployment_fixture%')->delete();
            DB::statement('DROP OWNED BY "'.$this->role.'"');
            DB::statement('DROP ROLE "'.$this->role.'"');
        }
        if ($this->dir) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    private function fixture(bool $fail = false): string
    {
        $file = $this->dir.'/2099_01_01_000001_deployment_fixture'.($fail ? '_failure' : '').'.php';
        file_put_contents($file, '<?php return new class extends \Illuminate\Database\Migrations\Migration {
            public function up(): void {
                \Illuminate\Support\Facades\Schema::create("migration_deployment_fixture", function ($t) { $t->string("role"); });
                $role = \Illuminate\Support\Facades\DB::selectOne("SELECT current_user AS role")?->role ?? "pretend";
                \Illuminate\Support\Facades\DB::table("migration_deployment_fixture")->insert(["role" => $role]);
                '.($fail ? 'throw new \RuntimeException("fixture failure secret-sentinel");' : '').'
            }
            public function down(): void { \Illuminate\Support\Facades\Schema::dropIfExists("migration_deployment_fixture"); }
        };');

        return $file;
    }

    public function test_owner_migration_and_pretend_leave_runtime_identity_and_config_unchanged(): void
    {
        $config = config('database');
        $service = app(MigrationDeployment::class);
        $this->assertSame([], $service->pending());
        $path = $this->fixture();
        $service->migrate($this->dir.'/owner.json', true, [$path]);
        $this->assertNull(DB::selectOne("SELECT to_regclass('mcp_trading.migration_deployment_fixture') AS relation")->relation);
        $service->migrate($this->dir.'/owner.json', false, [$path]);
        $this->assertSame($this->role, DB::selectOne('SELECT current_user AS role')->role);
        $this->assertSame($config, config('database'));
        DB::statement('RESET ROLE');
        $owner = DB::selectOne('SELECT current_user AS role')->role;
        $this->assertSame($owner, DB::table('migration_deployment_fixture')->value('role'));
        $this->assertSame('mcp_trading_test', DB::selectOne('SELECT current_database() AS db')->db);
        $this->assertNotNull(DB::table('migrations')->where('migration', basename($path, '.php'))->first());
    }

    public function test_failure_rolls_back_ddl_releases_lock_and_discards_owner_connection(): void
    {
        $config = config('database');
        $service = app(MigrationDeployment::class);
        try {
            $service->migrate($this->dir.'/owner.json', false, [$this->fixture(true)]);
            $this->fail('Failing migration succeeded.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('fixture failure', $error->getMessage());
        }
        $this->assertSame($config, config('database'));
        $this->assertSame($this->role, DB::selectOne('SELECT current_user AS role')->role);
        $this->assertNull(DB::selectOne("SELECT to_regclass('mcp_trading.migration_deployment_fixture') AS relation")->relation);
        $service->migrate($this->dir.'/owner.json', false, [$this->fixture()]);
    }

    public function test_non_owner_and_concurrent_deployment_are_refused_before_ddl(): void
    {
        file_put_contents($this->dir.'/wrong.json', json_encode(['username' => $this->role, 'password' => 'secret-sentinel']));
        chmod($this->dir.'/wrong.json', 0600);
        $service = app(MigrationDeployment::class);
        $path = $this->fixture();
        try {
            $service->migrate($this->dir.'/wrong.json', false, [$path]);
            $this->fail('Non-owner accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('verified schema owner', $error->getMessage());
        }
        DB::selectOne('SELECT pg_advisory_lock(1296257108, 1)');
        try {
            $service->migrate($this->dir.'/owner.json', false, [$path]);
            $this->fail('Concurrent migration accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Another schema deployment', $error->getMessage());
            $this->assertNull(DB::selectOne("SELECT to_regclass('mcp_trading.migration_deployment_fixture') AS relation")->relation);
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(1296257108, 1)');
        }
    }

    public function test_cli_failure_does_not_print_private_credentials_or_trace(): void
    {
        file_put_contents($this->dir.'/invalid.json', '{"username":"owner","password":"secret-sentinel","host":"unapproved"}');
        chmod($this->dir.'/invalid.json', 0600);
        $process = new Process([PHP_BINARY, base_path('deploy/macos/migrate.php'), '--apply'], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql_test',
            'DB_URL' => '',
            'MCP_MIGRATION_CREDENTIALS_FILE' => $this->dir.'/invalid.json',
        ]);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Schema deployment failed', $output);
        $this->assertStringNotContainsString('secret-sentinel', $output);
        $this->assertStringNotContainsString('Stack trace', $output);
    }
}
