<?php

namespace Tests\Unit\Operations;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RuntimeSnapshotTest extends TestCase
{
    public function test_snapshot_preserves_developer_index_and_keeps_credentials_out_of_its_commit(): void
    {
        $root = sys_get_temp_dir().'/mcp-release-test-'.bin2hex(random_bytes(6));
        $source = $root.'/source';
        mkdir($source.'/public/build', 0700, true);
        try {
            file_put_contents($source.'/.gitignore', ".env\n/public/build\n");
            file_put_contents($source.'/artisan', 'synthetic source');
            file_put_contents($source.'/.env', "APP_KEY=synthetic-private-key\nAPP_DEBUG=true\nTRADING_ENABLED=true\n");
            file_put_contents($source.'/public/build/manifest.json', '{}');
            foreach ([['git', 'init', '--quiet'], ['git', 'add', '.'], ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'baseline']] as $command) {
                (new Process($command, $source))->mustRun();
            }
            file_put_contents($source.'/artisan', 'changed working source');
            file_put_contents($source.'/new-service.php', 'untracked service');
            $status = (new Process(['git', 'status', '--porcelain'], $source))->mustRun()->getOutput();
            $result = (new Process(['python3', base_path('deploy/macos/prepare-runtime.py'), '--source', $source, '--runtime-root', $root.'/runtime']))->mustRun();
            $this->assertSame($status, (new Process(['git', 'status', '--porcelain'], $source))->mustRun()->getOutput());
            $app = $root.'/runtime/app';
            $this->assertSame('changed working source', file_get_contents($app.'/artisan'));
            $this->assertFileExists($app.'/new-service.php');
            $this->assertStringContainsString('APP_KEY=synthetic-private-key', file_get_contents($app.'/.env'));
            $this->assertStringContainsString('TRADING_ENABLED="false"', file_get_contents($app.'/.env'));
            $this->assertSame(0600, fileperms($app.'/.env') & 0777);
            $this->assertSame('', (new Process(['git', 'ls-files', '.env'], $app))->mustRun()->getOutput());
            $this->assertSame('', (new Process(['git', 'status', '--porcelain'], $app))->mustRun()->getOutput());
            $manifest = file_get_contents($app.'/DEPLOYMENT-SNAPSHOT.json');
            $this->assertStringNotContainsString('synthetic-private-key', $manifest);
            $this->assertFalse(json_decode($result->getOutput(), true)['services_started']);
            $second = new Process(['python3', base_path('deploy/macos/prepare-runtime.py'), '--source', $source, '--runtime-root', $root.'/runtime']);
            $second->run();
            $this->assertSame(1, $second->getExitCode());
            $this->assertStringContainsString('already exists', $second->getErrorOutput());
        } finally {
            File::deleteDirectory($root);
        }
    }
}
