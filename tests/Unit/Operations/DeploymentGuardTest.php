<?php

namespace Tests\Unit\Operations;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentGuardTest extends TestCase
{
    public function test_status_and_migration_failures_stop_deployment_before_runtime_reload(): void
    {
        $dir = sys_get_temp_dir().'/mcp-deploy-failure-'.bin2hex(random_bytes(6));
        mkdir($dir.'/app', 0700, true);
        mkdir($dir.'/bin', 0700);
        try {
            file_put_contents($dir.'/bin/git', "#!/bin/sh\ncase \"\$1\" in rev-parse) echo fixture-release;; esac\n");
            foreach (['composer', 'npm'] as $binary) {
                file_put_contents($dir.'/bin/'.$binary, "#!/bin/sh\nexit 0\n");
            }
            file_put_contents($dir.'/bin/php', <<<'SH'
#!/bin/sh
echo "$*" >> "$DEPLOY_TEST_LOG"
case "$*" in
  *migrate.php\ --check) exit "$DEPLOY_TEST_STATUS";;
  *migrate.php\ --apply) exit 1;;
esac
exit 0
SH);
            foreach (glob($dir.'/bin/*') as $binary) {
                chmod($binary, 0700);
            }
            foreach ([['1', [], 1, false], ['2', [], 2, false], ['2', ['--migrate'], 1, true]] as [$status, $args, $exit, $apply]) {
                file_put_contents($dir.'/calls', '');
                $process = new Process(['bash', base_path('deploy/macos/deploy.sh'), 'fixture', ...$args], null, [
                    'INVESTING_OPS_HOME' => $dir,
                    'PHP_BIN' => $dir.'/bin/php',
                    'PATH' => $dir.'/bin:'.getenv('PATH'),
                    'DEPLOY_TEST_LOG' => $dir.'/calls',
                    'DEPLOY_TEST_STATUS' => $status,
                ]);
                $process->run();
                $calls = file_get_contents($dir.'/calls');
                $this->assertSame($exit, $process->getExitCode(), $process->getErrorOutput());
                $this->assertSame($apply, str_contains($calls, 'migrate.php --apply'));
                $this->assertStringNotContainsString('optimize', $calls);
                $this->assertStringNotContainsString('queue:restart', $calls);
            }
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_deployment_refuses_dirty_checkout_before_fetch_install_or_service_changes(): void
    {
        $dir = sys_get_temp_dir().'/mcp-deploy-test-'.bin2hex(random_bytes(6));
        mkdir($dir.'/app', 0700, true);
        try {
            $init = new Process(['git', 'init', '--quiet'], $dir.'/app');
            $init->mustRun();
            file_put_contents($dir.'/app/uncommitted.txt', 'operator work');
            $process = new Process(['bash', base_path('deploy/macos/deploy.sh'), 'main'], null, ['INVESTING_OPS_HOME' => $dir]);
            $process->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('Runtime checkout has local changes', $process->getErrorOutput());
            $this->assertSame('operator work', file_get_contents($dir.'/app/uncommitted.txt'));
            $this->assertDirectoryDoesNotExist($dir.'/app/vendor');
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
