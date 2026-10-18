<?php

namespace Tests\Unit\Operations;

use App\Services\Operations\BackupCipher;
use App\Services\Operations\Backups;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BackupCipherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mcp-cipher-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir.'/keys');
        @rmdir($this->dir);
    }

    private function key(): string
    {
        BackupCipher::createKey($this->dir.'/keys/backup.key');

        return BackupCipher::key($this->dir.'/keys/backup.key');
    }

    public function test_round_trip_at_chunk_boundaries(): void
    {
        $key = $this->key();
        foreach ([0, 10, 65536, 65536 * 2 + 5] as $size) {
            $plain = random_bytes(max(1, $size));
            $plain = substr($plain, 0, $size);
            file_put_contents($this->dir.'/plain', $plain);
            BackupCipher::encrypt($this->dir.'/plain', $this->dir.'/enc', $key);
            // Ciphertext can contain any random byte, including "x" for an empty input.
            // Round-trip and authenticated tamper/wrong-key tests below verify the cipher contract.
            $this->assertNotSame($plain, (string) file_get_contents($this->dir.'/enc'));
            BackupCipher::decrypt($this->dir.'/enc', null, $key);
            BackupCipher::decrypt($this->dir.'/enc', $this->dir.'/out', $key);
            $this->assertSame($plain, file_get_contents($this->dir.'/out'));
            $this->assertSame(0600, fileperms($this->dir.'/enc') & 0777);
            unlink($this->dir.'/plain');
            unlink($this->dir.'/enc');
            unlink($this->dir.'/out');
        }
    }

    public function test_tampering_truncation_trailing_data_and_wrong_keys_are_rejected(): void
    {
        $key = $this->key();
        file_put_contents($this->dir.'/plain', random_bytes(70000));
        BackupCipher::encrypt($this->dir.'/plain', $this->dir.'/enc', $key);
        $original = (string) file_get_contents($this->dir.'/enc');
        $flipped = $original;
        $flipped[100] = chr(ord($flipped[100]) ^ 1);
        $variants = ['tampered' => [$flipped, $key], 'truncated' => [substr($original, 0, -10), $key], 'trailing' => [$original.'x', $key],
            'wrong key' => [$original, sodium_crypto_secretstream_xchacha20poly1305_keygen()], 'not a backup' => ['plain text', $key]];
        foreach ($variants as $name => [$bytes, $variantKey]) {
            file_put_contents($this->dir.'/variant', $bytes);
            try {
                BackupCipher::decrypt($this->dir.'/variant', $this->dir.'/out', $variantKey);
                $this->fail($name.' backup was accepted.');
            } catch (RuntimeException) {
                $this->assertFileDoesNotExist($this->dir.'/out', $name.' left partial plaintext behind.');
            }
            unlink($this->dir.'/variant');
        }
    }

    public function test_key_file_is_private_and_never_replaced(): void
    {
        $this->key();
        $file = $this->dir.'/keys/backup.key';
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertSame(32, strlen((string) base64_decode(trim((string) file_get_contents($file)), true)));
        $before = file_get_contents($file);
        try {
            BackupCipher::createKey($file);
            $this->fail('Existing key replaced.');
        } catch (RuntimeException) {
            $this->assertSame($before, file_get_contents($file));
        }
        chmod($file, 0644);
        $this->expectException(RuntimeException::class);
        BackupCipher::key($file);
    }

    public function test_retention_keeps_newest_per_day_and_per_week(): void
    {
        $names = [];
        foreach (range(0, 59) as $day) {
            $date = gmdate('Ymd', strtotime('2026-10-16 -'.$day.' days'));
            $names[] = 'mcp-trading-'.$date.'T023000Z.dump.enc';
            $names[] = 'mcp-trading-'.$date.'T143000Z.dump.enc';
        }
        $names[] = 'unrelated.txt';
        $keep = Backups::retain($names, 14, 8);
        $this->assertContains('mcp-trading-20261016T143000Z.dump.enc', $keep);
        $this->assertNotContains('mcp-trading-20261016T023000Z.dump.enc', $keep); // older backup of the same day
        $this->assertCount(14, array_filter($keep, fn ($name) => $name >= 'mcp-trading-20261003'));
        $this->assertLessThanOrEqual(14 + 8, count($keep));
        $this->assertNotContains('unrelated.txt', $keep);
        $this->assertNotContains('mcp-trading-20260818T143000Z.dump.enc', $keep); // older than eight weeks
    }
}
