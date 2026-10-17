<?php

namespace Tests\Unit\Operations;

use App\Services\Operations\MigrationCredentials;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class MigrationCredentialsTest extends TestCase
{
    public function test_private_credentials_accept_literal_password_without_loading_environment(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mcp-owner-');
        try {
            $values = ['username' => 'fixture_owner', 'password' => 'literal $ " \\ password'];
            file_put_contents($file, json_encode($values));
            chmod($file, 0600);
            $this->assertSame($values, MigrationCredentials::load($file));
            $this->assertNotSame('fixture_owner', config('database.connections.pgsql.username'));
        } finally {
            unlink($file);
        }
    }

    public function test_public_symlink_malformed_and_target_override_files_are_refused(): void
    {
        $dir = sys_get_temp_dir().'/mcp-owner-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            foreach (['public', 'symlink', 'malformed', 'target_override', 'missing_password'] as $case) {
                $file = $dir.'/'.$case;
                file_put_contents($file, match ($case) {
                    'malformed' => 'not json',
                    'target_override' => '{"username":"owner","password":"secret-sentinel","database":"postgres"}',
                    'missing_password' => '{"username":"owner"}',
                    default => '{"username":"owner","password":"secret-sentinel"}',
                });
                chmod($file, $case === 'public' ? 0644 : 0600);
                if ($case === 'symlink') {
                    symlink($file, $file.'-link');
                    $file .= '-link';
                }
                clearstatcache();
                try {
                    MigrationCredentials::load($file);
                    $this->fail('Unsafe credential file accepted: '.$case);
                } catch (RuntimeException $error) {
                    $this->assertStringNotContainsString('secret-sentinel', $error->getMessage());
                }
            }
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
