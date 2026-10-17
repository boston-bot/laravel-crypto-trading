<?php

namespace Tests\Feature\Operations;

use App\Models\Operations\ComponentStatus;
use App\Services\Operations\BackupCipher;
use App\Services\Operations\Backups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/mcp-backup-test-'.bin2hex(random_bytes(6));
        config(['operations.backup.dir' => $this->dir.'/local', 'operations.backup.key_file' => $this->dir.'/keys/backup.key', 'operations.backup.offsite_dir' => null]);
        // SQLite cannot run pg_dump: a stand-in writes recognizable bytes and reuses the real row counting.
        $this->app->instance(Backups::class, new class extends Backups
        {
            protected function capture(string $target): array
            {
                file_put_contents($target, 'synthetic dump bytes');

                return $this->counts(DB::connection());
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_backup_requires_a_private_key_and_records_failure(): void
    {
        $this->artisan('mcp:backup')->assertExitCode(1);
        $this->assertSame('failed', ComponentStatus::where('component', 'backup')->value('status'));
        $this->artisan('mcp:backup', ['--init-key' => true])->assertExitCode(0);
        $this->artisan('mcp:backup', ['--init-key' => true])->assertExitCode(1);
        $this->assertSame(0600, fileperms($this->dir.'/keys/backup.key') & 0777);
    }

    public function test_backup_is_encrypted_verified_manifested_and_copied_off_site(): void
    {
        BackupCipher::createKey($this->dir.'/keys/backup.key');
        $result = app(Backups::class)->run();
        $file = $this->dir.'/local/'.$result['file'];
        $this->assertStringNotContainsString('synthetic dump bytes', (string) file_get_contents($file));
        $this->assertSame([], glob($this->dir.'/local/.tmp-*') ?: []); // no plaintext left behind
        BackupCipher::decrypt($file, $this->dir.'/restored', BackupCipher::key($this->dir.'/keys/backup.key'));
        $this->assertSame('synthetic dump bytes', file_get_contents($this->dir.'/restored'));
        $manifest = json_decode((string) file_get_contents($file.'.json'), true);
        $this->assertSame(hash_file('sha256', $file), $manifest['sha256']);
        $this->assertArrayHasKey('migrations', $manifest['counts']);
        $this->assertSame(['offsite_unconfigured', 'unconfigured'], [ComponentStatus::where('component', 'backup')->value('status'), $result['offsite']]);

        config(['operations.backup.offsite_dir' => $this->dir.'/missing-drive']);
        $this->travel(1)->seconds();
        $this->assertSame('missing', app(Backups::class)->run()['offsite']);
        mkdir($this->dir.'/drive', 0700);
        config(['operations.backup.offsite_dir' => $this->dir.'/drive']);
        $this->travel(1)->seconds();
        $copied = app(Backups::class)->run();
        $this->assertSame('copied', $copied['offsite']);
        $this->assertSame(hash_file('sha256', $this->dir.'/local/'.$copied['file']), hash_file('sha256', $this->dir.'/drive/'.$copied['file']));
        $this->assertFileExists($this->dir.'/drive/'.$copied['file'].'.json');
        $this->assertSame('ok', ComponentStatus::where('component', 'backup')->value('status'));
    }

    public function test_concurrent_and_same_second_backups_refuse_to_overwrite_evidence(): void
    {
        BackupCipher::createKey($this->dir.'/keys/backup.key');
        mkdir($this->dir.'/local', 0700, true);
        $lock = fopen($this->dir.'/local/.backup.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            try {
                app(Backups::class)->run();
                $this->fail('A competing backup was allowed.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('already running', $error->getMessage());
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->freezeTime();
        $first = app(Backups::class)->run();
        try {
            app(Backups::class)->run();
            $this->fail('A same-second backup was allowed to overwrite evidence.');
        } catch (\RuntimeException) {
            $this->assertSame($first['sha256'], hash_file('sha256', $this->dir.'/local/'.$first['file']));
            $this->assertSame([], glob($this->dir.'/local/.tmp-*') ?: []);
        }
    }

    public function test_retention_prunes_old_local_backups_with_their_manifests(): void
    {
        BackupCipher::createKey($this->dir.'/keys/backup.key');
        mkdir($this->dir.'/local', 0700, true);
        foreach (range(1, 40) as $day) {
            $name = 'mcp-trading-'.now()->subDays($day)->format('Ymd').'T023000Z.dump.enc';
            touch($this->dir.'/local/'.$name);
            touch($this->dir.'/local/'.$name.'.json');
        }
        $result = app(Backups::class)->run();
        $remaining = glob($this->dir.'/local/mcp-trading-*.dump.enc') ?: [];
        $this->assertGreaterThan(0, $result['pruned']);
        $this->assertLessThanOrEqual(22, count($remaining));
        $this->assertSame(count($remaining), count(glob($this->dir.'/local/mcp-trading-*.dump.enc.json') ?: []));
        $this->assertFileExists($this->dir.'/local/'.$result['file']);
    }
}
