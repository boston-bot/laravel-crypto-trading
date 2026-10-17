<?php

namespace App\Services\Operations;

use App\Models\McpConnection;
use App\Models\McpOauthCredential;
use App\Models\Monitoring\PolicyVersion;
use App\Models\Operations\ComponentStatus;
use App\Support\Mcp\DatabaseReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Encrypted, verified backups of the application schema. The backup key and APP_KEY must be stored separately from
 * the backups: without the first nothing decrypts, without the second encrypted columns are unreadable.
 */
class Backups
{
    public const COUNTED = ['migrations', 'users', 'mcp_connections', 'mcp_runs', 'mcp_snapshots', 'mcp_holdings', 'mcp_incidents', 'mcp_policy_versions',
        'mcp_thesis_versions', 'mcp_research_runs', 'mcp_research_sources', 'mcp_summaries'];

    public function run(): array
    {
        $config = config('operations.backup');
        $key = BackupCipher::key($config['key_file']);
        $dir = rtrim($config['dir'], '/');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new RuntimeException('Cannot create the backup directory.');
        }
        $lock = fopen($dir.'/.backup.lock', 'c');
        if (! $lock) {
            throw new RuntimeException('Cannot create the backup lock.');
        }
        chmod($dir.'/.backup.lock', 0600);
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another backup is already running.');
            }

            return $this->createBackup($config, $key, $dir);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function createBackup(array $config, string $key, string $dir): array
    {
        $stamp = CarbonImmutable::now('UTC')->format('Ymd\THis\Z');
        $plain = $dir.'/.tmp-'.$stamp.'.dump';
        $file = $dir.'/mcp-trading-'.$stamp.'.dump.enc';
        if (file_exists($file) || file_exists($plain)) {
            throw new RuntimeException('A backup with this timestamp already exists; nothing was overwritten.');
        }
        try {
            $counts = $this->capture($plain);
            BackupCipher::encrypt($plain, $file, $key);
        } finally {
            if (is_file($plain)) {
                unlink($plain);
            }
        }
        BackupCipher::decrypt($file, null, $key);
        $manifest = ['file' => basename($file), 'sha256' => hash_file('sha256', $file), 'bytes' => filesize($file), 'created_at' => CarbonImmutable::now()->toIso8601String(),
            'schema' => DB::connection()->getConfig('search_path'), 'counts' => $counts, 'format' => 'pg_dump custom format; libsodium secretstream'];
        file_put_contents($file.'.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($file.'.json', 0600);
        $pruned = $this->prune($dir);
        $offsite = $this->copyOffsite($file, $config['offsite_dir']);
        ComponentStatus::record('backup', $offsite === 'copied' ? 'ok' : 'offsite_'.$offsite, ['file' => basename($file), 'bytes' => $manifest['bytes'], 'pruned' => $pruned]);

        return $manifest + ['pruned' => $pruned, 'offsite' => $offsite];
    }

    /** Restores the newest (or given) backup into a fresh scratch database, verifies it is usable, then drops it. */
    public function restoreDrill(?string $file = null): array
    {
        $config = config('operations.backup');
        $key = BackupCipher::key($config['key_file']);
        $file ??= collect(glob(rtrim($config['dir'], '/').'/mcp-trading-*.dump.enc') ?: [])->sort()->last();
        $manifest = $file && is_file($file.'.json') ? json_decode((string) file_get_contents($file.'.json'), true) : null;
        if (! $file || ! is_array($manifest) || hash_file('sha256', $file) !== $manifest['sha256']) {
            throw new RuntimeException('No backup with a matching manifest was found.');
        }
        $database = 'mcp_trading_restore_drill_'.CarbonImmutable::now('UTC')->format('YmdHis').'_'.bin2hex(random_bytes(6));
        if (in_array($database, [DB::connection()->getDatabaseName(), 'mcp_trading_test', 'postgres'], true)) {
            throw new RuntimeException('Refusing to use a non-scratch database for the drill.');
        }
        $plain = sys_get_temp_dir().'/'.$database.'.dump';
        $drill = ['host' => $config['drill_host'], 'username' => $config['drill_user'] ?: get_current_user(), 'password' => ''];
        BackupCipher::decrypt($file, $plain, $key);
        $created = false;
        try {
            $this->binary('createdb', [$database], $drill);
            $created = true;
            $this->binary('pg_restore', ['--no-owner', '--no-acl', '--exit-on-error', '--dbname='.$database, $plain], $drill);
            // No URL: it would override the scratch host and database.
            config(['database.connections.restore_drill' => array_merge(DB::connection()->getConfig(), $drill, ['url' => null, 'database' => $database, 'name' => 'restore_drill'])]);
            $restored = DB::connection('restore_drill');
            if ($restored->selectOne('SELECT current_schema() AS schema')->schema !== $manifest['schema']) {
                throw new RuntimeException('Restored schema is missing.');
            }
            $counts = $this->counts($restored);
            if ($counts !== $manifest['counts']) {
                throw new RuntimeException('Restored row counts differ from the backup manifest.');
            }
            // Usable only with the separately stored APP_KEY; restored policy remains disabled for execution.
            $decrypted = true;
            foreach ((new McpConnection)->setConnection('restore_drill')->newQuery()->cursor() as $connection) {
                $connection->getAttribute('bound_account_id');
                if ($connection->capability_manifest !== null && ! is_array($connection->capability_manifest)) {
                    throw new RuntimeException('Restored encrypted columns are unreadable.');
                }
            }
            foreach ((new McpOauthCredential)->setConnection('restore_drill')->newQuery()->cursor() as $credential) {
                foreach (['access_token', 'refresh_token', 'oauth_metadata'] as $field) {
                    // Exercise all restored OAuth records, never expose their values.
                    $credential->getAttribute($field);
                }
            }
            $modes = (new PolicyVersion)->setConnection('restore_drill')->newQuery()->get()->map(fn ($p) => $p->payload['mode'] ?? null)->unique()->values()->all();
            if (array_diff($modes, ['disabled']) !== []) {
                throw new RuntimeException('Restored policy is not execution-disabled.');
            }
        } finally {
            DB::purge('restore_drill');
            try {
                if ($created) {
                    $this->binary('dropdb', ['--if-exists', $database], $drill);
                }
            } finally {
                @unlink($plain); // Even a failed scratch-database cleanup must not retain plaintext.
            }
        }
        $result = ['backup' => basename($file), 'tables_verified' => count($counts), 'rows' => array_sum($counts), 'encrypted_columns_readable' => $decrypted, 'scratch_database_dropped' => true];
        ComponentStatus::record('restore_drill', 'ok', $result);

        return $result;
    }

    /** Keeps the newest backup of each of the last N days and of the last M ISO weeks. */
    public static function retain(array $names, int $daily, int $weekly): array
    {
        rsort($names);
        $days = [];
        $weeks = [];
        foreach ($names as $name) {
            if (! preg_match('/^mcp-trading-(\d{8})T\d{6}Z\.dump\.enc$/', $name, $m)) {
                continue;
            }
            $date = CarbonImmutable::createFromFormat('!Ymd', $m[1], 'UTC');
            if (! isset($days[$m[1]]) && count($days) < $daily) {
                $days[$m[1]] = $name;
            }
            if (! isset($weeks[$date->format('o-W')]) && count($weeks) < $weekly) {
                $weeks[$date->format('o-W')] = $name;
            }
        }

        return array_values(array_unique(array_merge(array_values($days), array_values($weeks))));
    }

    /** Row counts and the dump come from one exported snapshot, so the manifest describes exactly what was dumped. */
    protected function capture(string $target): array
    {
        app(DatabaseReadiness::class)->inspect();

        return DB::transaction(function () use ($target): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = DB::selectOne('SELECT pg_export_snapshot() AS id')->id;
            $counts = $this->counts(DB::connection());
            $db = DB::connection()->getConfig();
            $this->binary('pg_dump', ['--format=custom', '--schema='.$db['search_path'], '--snapshot='.$snapshot, '--file='.$target, $db['database']],
                ['host' => $db['host'], 'port' => $db['port'] ?? null, 'username' => $db['username'], 'password' => (string) ($db['password'] ?? '')]);

            return $counts;
        });
    }

    private function binary(string $name, array $arguments, array $login): void
    {
        $env = ['PGHOST' => $login['host'], 'PGPORT' => (string) ($login['port'] ?? '5432'), 'PGUSER' => $login['username'], 'PGCONNECT_TIMEOUT' => '10'];
        $passfile = null;
        if (($login['password'] ?? '') !== '') {
            // Supplied through a private pass file, never on the command line or in the process environment.
            $passfile = tempnam(sys_get_temp_dir(), 'pgpass-');
            chmod($passfile, 0600);
            file_put_contents($passfile, '*:*:*:*:'.strtr($login['password'], ['\\' => '\\\\', ':' => '\\:'])."\n");
            $env['PGPASSFILE'] = $passfile;
        }
        try {
            $process = new Process([rtrim(config('operations.backup.pg_bin'), '/').'/'.$name, '--no-password', ...$arguments], null, $env, null, 900);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException($name.' failed with exit code '.$process->getExitCode().'.');
            }
        } finally {
            if ($passfile) {
                @unlink($passfile);
            }
        }
    }

    protected function counts($connection): array
    {
        $counts = [];
        $tables = $connection->getDriverName() === 'pgsql'
            ? array_column($connection->select("SELECT tablename FROM pg_tables WHERE schemaname = 'mcp_trading' ORDER BY tablename"), 'tablename')
            : self::COUNTED; // SQLite capture stand-ins only; real backups require PostgreSQL.
        foreach ($tables as $table) {
            if (Schema::connection($connection->getName())->hasTable($table)) {
                $counts[$table] = $connection->table($table)->count();
            }
        }

        return $counts;
    }

    private function prune(string $dir): int
    {
        $names = array_map('basename', glob($dir.'/mcp-trading-*.dump.enc') ?: []);
        $keep = self::retain($names, (int) config('operations.backup.keep_daily'), (int) config('operations.backup.keep_weekly'));
        $removed = 0;
        foreach (array_diff($names, $keep) as $name) {
            @unlink($dir.'/'.$name.'.json');
            $removed += (int) @unlink($dir.'/'.$name);
        }

        return $removed;
    }

    private function copyOffsite(string $file, ?string $dir): string
    {
        if (! $dir) {
            return 'unconfigured';
        }
        if (! is_dir($dir) || ! is_writable($dir)) {
            return 'missing';
        }
        $target = rtrim($dir, '/').'/'.basename($file);
        if (! copy($file, $target) || ! copy($file.'.json', $target.'.json') || hash_file('sha256', $target) !== hash_file('sha256', $file)) {
            return 'failed';
        }
        $this->prune(rtrim($dir, '/'));

        return 'copied';
    }
}
