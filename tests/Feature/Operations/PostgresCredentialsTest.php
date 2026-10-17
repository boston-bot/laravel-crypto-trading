<?php

namespace Tests\Feature\Operations;

use App\Services\Operations\Backups;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

class PostgresCredentialsTest extends TestCase
{
    public function test_password_with_colon_and_backslash_survives_the_private_passfile_boundary(): void
    {
        $dir = sys_get_temp_dir().'/mcp-pgpass-test-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            // A stand-in PostgreSQL client observes the exact passfile supplied by the real subprocess boundary.
            file_put_contents($dir.'/pg_dump', <<<'SCRIPT'
#!/usr/bin/env php
<?php
$path = getenv('PGPASSFILE');
file_put_contents($argv[count($argv) - 1], json_encode(['line' => file_get_contents($path), 'permissions' => fileperms($path) & 0777, 'path' => $path]));
SCRIPT);
            chmod($dir.'/pg_dump', 0700);
            config(['operations.backup.pg_bin' => $dir]);
            $password = 'synthetic:colon\\backslash';
            (new ReflectionMethod(Backups::class, 'binary'))->invoke(app(Backups::class), 'pg_dump', [$dir.'/observation.json'],
                ['host' => '/tmp', 'username' => 'synthetic', 'password' => $password]);
            $observation = json_decode(file_get_contents($dir.'/observation.json'), true);
            // Parse the documented PostgreSQL passfile escaping: a backslash quotes the next character.
            $fields = [''];
            $escaped = false;
            foreach (str_split(rtrim($observation['line'], "\n")) as $character) {
                if ($escaped) {
                    $fields[count($fields) - 1] .= $character;
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === ':') {
                    $fields[] = '';
                } else {
                    $fields[count($fields) - 1] .= $character;
                }
            }
            $this->assertCount(5, $fields);
            $this->assertSame($password, $fields[4]);
            $this->assertSame(0600, $observation['permissions']);
            $this->assertFileDoesNotExist($observation['path']);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
