<?php

namespace Tests;

use App\Support\Mcp\DatabaseReadiness;
use App\Support\Mcp\TestDatabaseGuard;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    private ?string $executionFenceDir = null;

    public function createApplication()
    {
        if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new \RuntimeException('Clear cached runtime configuration before testing.');
        }
        $app = parent::createApplication();
        $runtime = is_file($app->basePath('.env'))
            ? Dotenv::parse(file_get_contents($app->basePath('.env'))) : [];
        $runtimeDatabase = $runtime['DB_DATABASE'] ?? null;
        if (! empty($runtime['DB_URL'])) {
            $runtimeDatabase = ltrim(parse_url($runtime['DB_URL'], PHP_URL_PATH) ?? '', '/');
        }
        $connection = DB::connection();
        TestDatabaseGuard::check($connection->getDriverName(), $connection->getDatabaseName(), $runtimeDatabase);
        if ($connection->getDriverName() === 'pgsql') {
            $actual = $connection->selectOne('SELECT current_database() AS database');
            TestDatabaseGuard::check('pgsql', $actual->database, $runtimeDatabase);
            app(DatabaseReadiness::class)->inspect();
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->executionFenceDir = sys_get_temp_dir().'/mcp-execution-fence-test-'.bin2hex(random_bytes(8));
        config(['mcp.execution_recovery_dir' => $this->executionFenceDir]);
        $this->withoutVite(); // HTTP tests must not depend on a local frontend build
    }

    protected function tearDown(): void
    {
        if ($this->executionFenceDir) {
            File::deleteDirectory($this->executionFenceDir);
        }
        parent::tearDown();
    }
}
