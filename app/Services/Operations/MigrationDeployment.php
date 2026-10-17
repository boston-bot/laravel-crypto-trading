<?php

namespace App\Services\Operations;

use App\Support\Mcp\DatabaseReadiness;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

final class MigrationDeployment
{
    // Session-level PostgreSQL lock shared by all migration deployment processes.
    private const LOCK = [1296257108, 1];

    public function pending(): array
    {
        app(DatabaseReadiness::class)->inspect();
        $migrator = app('migrator');

        return $migrator->usingConnection(config('database.default'), function () use ($migrator) {
            if (! $migrator->repositoryExists()) {
                throw new RuntimeException('Initialize the verified schema separately before deployment.');
            }

            return array_values(array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan()));
        });
    }

    /** No env/config-cache writes. Host, database and schema always come from verified runtime config. */
    public function migrate(string $credentialFile, bool $pretend = false, ?array $paths = null): string
    {
        $runtime = app(DatabaseReadiness::class)->inspect();
        $credentials = MigrationCredentials::load($credentialFile);
        if ($credentials['username'] !== $runtime['owner'] || $credentials['username'] === $runtime['role']) {
            throw new RuntimeException('Use the verified schema owner, separate from the runtime login.');
        }
        $name = 'mcp_deployment_owner';
        if (config('database.connections.'.$name) !== null) {
            throw new RuntimeException('Migration connection name is already in use.');
        }
        $connection = null;
        $locked = false;
        $default = config('database.default');
        $connections = config('database.connections');
        try {
            config(['database.connections.'.$name => array_merge(DB::connection()->getConfig(), $credentials, ['url' => null, 'name' => $name])]);
            $connection = DB::connection($name);
            $identity = $connection->selectOne('SELECT current_database() AS database, current_schema() AS schema, current_user AS role');
            if ($identity->database !== $runtime['database'] || $identity->schema !== $runtime['schema'] || $identity->role !== $runtime['owner']) {
                throw new RuntimeException('Migration connection differs from the verified database/schema owner.');
            }
            $locked = (bool) $connection->selectOne('SELECT pg_try_advisory_lock(?, ?) AS locked', self::LOCK)->locked;
            if (! $locked) {
                throw new RuntimeException('Another schema deployment is running.');
            }
            $output = new BufferedOutput;
            $options = ['--database' => $name, '--force' => true, '--no-interaction' => true, '--pretend' => $pretend];
            if ($paths !== null) {
                $options += ['--path' => $paths, '--realpath' => true];
            }
            if (Artisan::call('migrate', $options, $output) !== 0) {
                throw new RuntimeException('Migration command failed; services were not restarted.');
            }

            return $output->fetch();
        } finally {
            try {
                if ($locked) {
                    $connection->selectOne('SELECT pg_advisory_unlock(?, ?)', self::LOCK);
                }
            } finally {
                DB::purge($name);
                config(['database.connections' => $connections]);
                config(['database.default' => $default]);
            }
        }
    }
}
