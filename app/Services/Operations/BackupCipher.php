<?php

namespace App\Services\Operations;

use RuntimeException;

/** Authenticated, chunked encryption (libsodium secretstream). Tampering, truncation or a wrong key fail decryption. */
final class BackupCipher
{
    private const MAGIC = "MCPBK1\n";

    private const CHUNK = 65536;

    public static function createKey(string $file): void
    {
        if (file_exists($file)) {
            throw new RuntimeException('Backup key already exists; it was not replaced.');
        }
        $dir = dirname($file);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new RuntimeException('Cannot create the backup key directory.');
        }
        $handle = fopen($file, 'x');
        if (! $handle) {
            throw new RuntimeException('Cannot create the backup key file.');
        }
        chmod($file, 0600);
        fwrite($handle, base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen())."\n");
        fclose($handle);
    }

    public static function key(string $file): string
    {
        clearstatcache(true, $file);
        if (! is_file($file) || (fileperms($file) & 0077) !== 0) {
            throw new RuntimeException('Backup key file is missing or readable by other users.');
        }
        $key = base64_decode(trim((string) file_get_contents($file)), true);
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('Backup key file is invalid.');
        }

        return $key;
    }

    public static function encrypt(string $source, string $target, string $key): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($target, 'xb');
        if (! $in || ! $out) {
            throw new RuntimeException('Cannot open backup files for encryption.');
        }
        chmod($target, 0600);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($out, self::MAGIC.$header);
        $size = filesize($source);
        $read = 0;
        do {
            $chunk = (string) fread($in, self::CHUNK);
            $read += strlen($chunk);
            $final = $read >= $size || feof($in);
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '',
                $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            fwrite($out, pack('N', strlen($cipher)).$cipher);
        } while (! $final);
        fclose($in);
        fclose($out);
    }

    /** Decrypts to $target, or only verifies integrity when $target is null. */
    public static function decrypt(string $source, ?string $target, string $key): void
    {
        $in = fopen($source, 'rb');
        $out = $target === null ? null : fopen($target, 'xb');
        if (! $in || ($target !== null && ! $out)) {
            throw new RuntimeException('Cannot open backup files for decryption.');
        }
        if ($out) {
            chmod($target, 0600);
        }
        try {
            $header = fread($in, strlen(self::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (! is_string($header) || ! str_starts_with($header, self::MAGIC) || strlen($header) !== strlen(self::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('Not an application backup file.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, strlen(self::MAGIC)), $key);
            do {
                $length = fread($in, 4);
                if (! is_string($length) || strlen($length) !== 4) {
                    throw new RuntimeException('Backup is truncated.');
                }
                $size = unpack('N', $length)[1];
                if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $size > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('Backup is corrupt.');
                }
                $cipher = fread($in, $size);
                $result = is_string($cipher) && strlen($cipher) === $size ? sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher) : false;
                if ($result === false) {
                    throw new RuntimeException('Backup failed authentication: wrong key, tampering or corruption.');
                }
                [$plain, $tag] = $result;
                if ($out) {
                    fwrite($out, $plain);
                }
            } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            if (fread($in, 1) !== '') {
                throw new RuntimeException('Backup has trailing data.');
            }
        } catch (RuntimeException $error) {
            if ($out) {
                fclose($out);
                $out = null;
                @unlink($target);
            }
            throw $error;
        } finally {
            fclose($in);
            if ($out) {
                fclose($out);
            }
        }
    }
}
