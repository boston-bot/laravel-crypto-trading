<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FpmPostgresStartupTest extends TestCase
{
    public function test_forked_workers_connect_after_cold_starts_and_recover_from_auth_failure(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test' || PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('Requires the dedicated PostgreSQL fixture and macOS PHP-FPM.');
        }
        $fpmOverride = getenv('MCP_TEST_PHP_FPM_BIN');
        $fpm = $fpmOverride ?: dirname(PHP_BINARY, 2).'/sbin/php-fpm';
        if (! is_executable($fpm)) {
            if ($fpmOverride) {
                $this->fail('Configured MCP_TEST_PHP_FPM_BIN is not executable.');
            }
            $this->markTestSkipped('Matching PHP-FPM executable unavailable.');
        }
        $version = (new Process([$fpm, '-v']))->mustRun()->getOutput();
        $this->assertStringContainsString('PHP '.PHP_VERSION.' (fpm-fcgi)', $version);
        $this->assertSame('mcp_trading_test', DB::selectOne('SELECT current_database() AS db')->db);
        $role = 'mcp_fpm_fixture_'.bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(24));
        $dir = '/tmp/mcp-fpm-'.bin2hex(random_bytes(5));
        mkdir($dir, 0700);
        $process = null;
        DB::statement('CREATE ROLE "'.$role.'" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '.DB::getPdo()->quote($password));
        try {
            DB::statement('GRANT CONNECT ON DATABASE "mcp_trading_test" TO "'.$role.'"');
            DB::statement('GRANT USAGE ON SCHEMA "mcp_trading" TO "'.$role.'"');
            $credentials = ['role' => $role, 'password' => $password];
            $saveCredentials = function (array $value) use ($dir): void {
                file_put_contents($dir.'/credentials.json', json_encode($value, JSON_THROW_ON_ERROR));
                chmod($dir.'/credentials.json', 0600);
            };
            $saveCredentials($credentials);
            // Real pool template, forced recycling, and a conflicting parent setting exercise the fork boundary.
            $config = str_replace(['@@RUNTIME@@', '@@LOGS@@', 'pm.max_requests = 500'], [$dir, $dir, 'pm.max_requests = 2'], file_get_contents(base_path('deploy/macos/templates/php-fpm.conf')));
            $config .= "\nenv[MCP_FPM_TEST_CREDENTIALS] = $dir/credentials.json\n";
            file_put_contents($dir.'/fpm.conf', $config);
            file_put_contents($dir.'/probe.php', <<<'PHP'
<?php
header('Content-Type: application/json');
$fixture = json_decode(file_get_contents(getenv('MCP_FPM_TEST_CREDENTIALS')), true, 512, JSON_THROW_ON_ERROR);
try {
    $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=mcp_trading_test;sslmode=prefer;connect_timeout=3', $fixture['role'], $fixture['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('SET search_path TO "mcp_trading"');
    $row = $db->query('SELECT current_database() AS db, current_schema() AS schema, current_user AS role')->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ready' => $row['db'] === 'mcp_trading_test' && $row['schema'] === 'mcp_trading' && $row['role'] === $fixture['role'], 'gss' => getenv('PGGSSENCMODE')]);
} catch (PDOException $error) {
    http_response_code(503);
    echo '{"ready":false}';
}
PHP);
            for ($start = 0; $start < 2; $start++) {
                $process = new Process([$fpm, '--nodaemonize', '--fpm-config', $dir.'/fpm.conf'], null, ['PGGSSENCMODE' => 'prefer']);
                $process->start();
                $deadline = microtime(true) + 5;
                while (! file_exists($dir.'/php-fpm.sock') && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(20000);
                }
                $this->assertTrue($process->isRunning(), 'Isolated FPM failed to start.');
                for ($request = 0; $request < 6; $request++) {
                    $reply = $this->request($dir);
                    $this->assertStringContainsString('"ready":true', $reply);
                    $this->assertStringContainsString('"gss":"disable"', $reply);
                }
                $saveCredentials(['role' => $role.'_missing', 'password' => 'invalid-fixture-password']);
                $reply = $this->request($dir);
                $this->assertStringContainsString('Status: 503', $reply);
                $this->assertStringNotContainsString('invalid-fixture-password', $reply);
                $saveCredentials($credentials);
                $this->assertStringContainsString('"ready":true', $this->request($dir));
                $process->stop(3);
                $this->assertStringNotContainsString('SIGSEGV', file_get_contents($dir.'/php-fpm.log'));
            }
        } finally {
            $process?->stop(3);
            DB::statement('DROP OWNED BY "'.$role.'"');
            DB::statement('DROP ROLE "'.$role.'"');
            File::deleteDirectory($dir);
        }
    }

    private function request(string $dir): string
    {
        $socket = stream_socket_client('unix://'.$dir.'/php-fpm.sock', $errno, $error, 5);
        $this->assertIsResource($socket, 'Isolated FPM socket unavailable.');
        stream_set_timeout($socket, 5);
        $record = static fn (int $type, string $body): string => pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0).$body;
        $params = '';
        foreach (['SCRIPT_FILENAME' => $dir.'/probe.php', 'REQUEST_METHOD' => 'GET', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'CONTENT_LENGTH' => '0'] as $key => $value) {
            $params .= chr(strlen($key)).chr(strlen($value)).$key.$value;
        }
        fwrite($socket, $record(1, pack('nCxxxxx', 1, 0)).$record(4, $params).$record(4, '').$record(5, ''));
        $reply = '';
        while (! feof($socket)) {
            $header = $this->readBytes($socket, 8);
            $fields = unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved', $header);
            $body = $this->readBytes($socket, $fields['length']);
            $this->readBytes($socket, $fields['padding']);
            if ($fields['type'] === 6) {
                $reply .= $body;
            }
            if ($fields['type'] === 3) {
                break;
            }
        }
        fclose($socket);

        return $reply;
    }

    private function readBytes($stream, int $length): string
    {
        $value = '';
        while (strlen($value) < $length) {
            $part = fread($stream, $length - strlen($value));
            $this->assertNotFalse($part);
            $this->assertNotSame('', $part, 'FPM closed or timed out before completing its response.');
            $value .= $part;
        }

        return $value;
    }
}
